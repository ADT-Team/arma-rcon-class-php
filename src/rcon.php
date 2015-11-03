<?php

/**
 *
 * ARC, an easy-to-use PHP class to send commands via  RCon to Arma servers.
 *
 * @author   Felix Schäfer <nizari@starwolf-dev.com>
 * @since    September 26, 2015
 * @link     https://github.com/Nizarii/arma-rcon-php-class
 * @license  MIT-License
 * @version  1.3.0
 *
 */


namespace Nizarii {


    class ARC {


        /**
         * ARC Options
         *
         * @var array
         */
        public $options = array (
            'send_heartbeat'       => false,
            'timeout_seconds'      => 1,
        );


        /**
         * Server IP
         *  The server IP of the BattlEye server where the commands are going send to.
         *
         * @var string
         */
        public $serverIP;


        /**
         * Server port
         *  The specific port of the BattlEye server.
         *
         * @var int
         */
        public $serverPort;


        /**
         * RCon password
         *  The required password for logging in.
         *
         * @var string
         */
        public $RCONpassword;


        /**
         * Socket
         *  The required socket for sending commands
         *
         * @var Socket
         */
        private $socket = null;


        /**
         * Header
         *  The header of a sent message
         *
         * @var string
         */
        private $header = null;


        /**
         * Class constructor
         *
         * @param string $serverIP        IP of the Arma server
         * @param integer $serverPort     Port of the Arma server
         * @param string  $RCONpassword   RCon password required by BattlEye
         * @param array  $options         Options array of ARC
         *
         * @throws SocketException        If creating the socket fails
         */
        public function __construct($serverIP, $serverPort = 2302, $RCONpassword ,array $options = array())
        {
            $this->serverIP = $serverIP;

            $this->serverPort = $serverPort;

            $this->RCONpassword = $RCONpassword;

            $this->options = array_merge($this->options, $options);
        }


        /**
         * Class destructor
         */
        public function __destruct()
        {
            $this->disconnect();
        }


        /**
         * Sends the login data to the server in order to send commands later
         *
         * @throws AuthorizationException      If login fails (password wrong)
         */
        private function authorize()
        {
            $loginmsg = $this->get_loginmessage();
            $sent = fwrite($this->socket, $loginmsg);

            if($sent == false)
            {
                throw new PacketException('[ARC] Failed to send login!');
            }

            $res = fread($this->socket, 16);

            if(ord($res[strlen($res)-1]) == 0)
            {
                throw new AuthorizationException('[ARC] Login failed, wrong password!');
            }

            if ($this->options['send_heartbeat'])
            {
                $this->send_heartbeat();
            }
        }


        /**
         * Generates the password's CRC32 data
         *
         * @return string
         */
        private function get_authCRC()
        {
            $authCRC = sprintf("%x", crc32(chr(255).chr(00).trim($this->RCONpassword)));
            $authCRC = array(substr($authCRC,-2,2),substr($authCRC,-4,2),substr($authCRC,-6,2),substr($authCRC,0,2));

            return $authCRC;
        }


        /**
         * Generates the message's CRC32 data
         *
         * @param string $command    The message which will be prepared for being sent to the server
         * @return string            Message which can be sent to the server
         */
        private function get_msgCRC($command)
        {

            $msgCRC = sprintf("%x", crc32(chr(255).chr(01).chr(hexdec(sprintf('%01b', 0))).$command));
            $msgCRC = array(substr($msgCRC,-2,2),substr($msgCRC,-4,2),substr($msgCRC,-6,2),substr($msgCRC,0,2));

            return $msgCRC;
        }


        /**
         * Generates the login message
         *
         * @return string     The message for logging in, containing the RCon password
         */
        private function get_loginmessage()
        {
            $authCRC = $this->get_authCRC();

            $loginmsg = "BE".chr(hexdec($authCRC[0])).chr(hexdec($authCRC[1])).chr(hexdec($authCRC[2])).chr(hexdec($authCRC[3]));
            $loginmsg .= chr(hexdec('ff')).chr(hexdec('00')).$this->RCONpassword;

            return $loginmsg;
        }


        /**
         * Sends optional a heartbeat to the server
         *
         * @throws PacketException    If sending the command fails
         */
        private function send_heartbeat()
        {
            $hb_msg = "BE".chr(hexdec("7d")).chr(hexdec("8f")).chr(hexdec("ef")).chr(hexdec("73"));
            $hb_msg .= chr(hexdec('ff')).chr(hexdec('02')).chr(hexdec('00'));

            $sent = fwrite($this->socket, $hb_msg);

            if ($sent == false)
            {
                throw new PacketException('[ARC] Failed to send heartbeat packet!');
            }
        }


        /**
         * Receives the answer form the server
         *
         * @return string     Any answer from the server, except the log-in message
         */
        private function get_answer()
        {
            $answer = substr(fread($this->socket, 102400), strlen($this->header));

            while (strpos($answer,'RCon admin') !== false)
            {
                $answer = substr(fread($this->socket, 102400), strlen($this->header));
            }

            return $answer;
        }


        /**
         * The heart of this class - this function actually sends the RCON command
         *
         * @param string $command      The command sent to the server
         * @throws PacketException           If sending the command fails
         */
        private function send($command)
        {
            $msgCRC = $this->get_msgCRC($command);
            $head = "BE".chr(hexdec($msgCRC[0])).chr(hexdec($msgCRC[1])).chr(hexdec($msgCRC[2])).chr(hexdec($msgCRC[3])).chr(hexdec('ff')).chr(hexdec('01')).chr(hexdec(sprintf('%01b', 0)));
            $msg = $head.$command;

            $this->header = $head;

            $sent = fwrite($this->socket, $msg);

            if ($sent == false)
            {
                throw new PacketException('[ARC] Failed to send command to server');
            }
        }


        /**
         * Sends a custom command to the BattlEye server
         *
         * @param string $command    Command sent to the server
         * @return string            Answer from the server
         */
        public function command($command)
        {
            $this->send($command);
            return $this->get_answer();
        }


        /**
         * Kicks a player who is currently on the server
         *
         * @param integer $player   The player who should be kicked
         */
        public function kick_player($player)
        {
            $this->send("kick ".$player);
        }


        /**
         * Sends a global message to all players
         *
         * @param string $message   The message to send
         */
        public function say_global($message)
        {
            $this->send("Say -1 ".$message);
        }


        /**
         * Sends a message to a specific player
         *
         * @param integer $player   Player who is sent the message
         * @param string $message   The message for the player
         */
        public function say_player($player, $message)
        {
            $this->send("Say ".$player.$message);
        }


        /**
         * Loads the "scripts.txt" file without the need to restart the server
         */
        public function load_scripts()
        {
            $this->send("loadScripts");
        }


        /**
         * Changes the MaxPing value. If a player has a higher ping, he will be kicked from the server
         *
         * @param integer $ping   Max ping
         */
        public function max_ping($ping)
        {
            $this->send("MaxPing ".$ping);
        }


        /**
         * Changes the RCon password
         *
         * @param string $password   New password
         */
        public function change_password($password)
        {
            $this->send("RConPassword ".$password);
        }


        /**
         * (Re)load the BE ban list from bans.txt
         */
        public function load_bans()
        {
            $this->send("loadBans");
        }


        /**
         * Gets a list of all players currently on the server
         *
         * @return string  The list of all players on the server
         */
        public function get_players()
        {
            $this->send("players");
            return $this->get_answer();
        }


        /**
         * Gets a list of all bans
         *
         * @return string  The list of bans
         */
        public function get_bans()
        {
            $this->send("bans");
            return $this->get_answer();
        }


        /**
         * Closes the socket/connection. If you want to reconnect,
         * don't forget to call connect(), in order to create a new socket
         *
         * @see connect()
         */
        public function disconnect()
        {
            $this->send("Exit");

            fclose($this->socket);

            $this->socket = null;
        }


        /**
         * Creates again a connection to the server,
         * only required if you manually closed the connection!
         *
         * @internal Also executes the authorization process.
         *
         * @see disconnect()
         */
        public function connect()
        {
            $this->socket = fsockopen("udp://".$this->serverIP, $this->serverPort, $errno, $errstr, $this->options['timeout_seconds']);

            stream_set_timeout($this->socket, $this->options['timeout_seconds']);
            stream_set_blocking($this->socket, true);

            if(!$this->socket)
            {
                throw new SocketException('[ARC] Failed to create socket!');
            }

            $this->authorize();
        }


        /**
         * Gets a list of all bans
         *
         * @return string  The list of missions
         */
        public function get_missions()
        {
            $this->send("missions");
            return $this->get_answer();
        }


        /**
         * Ban a player's BE GUID from the server. If time is not specified or 0, the ban will be permanent;.
         * If reason is not specified the player will be kicked with the message "Banned".
         *
         * @param string $player   Player who will be banned
         * @param string $reason   Reason why the player is banned
         * @param integer $time    How long the player is banned (0 = permanent)
         */
        public function ban_player($player, $reason = "Banned", $time = 0)
        {
            $this->send("ban ".$player." ".$time." ".$reason);
        }


        /**
         * Same as "ban_player", but allows to ban a player that is not currently on the server
         *
         * @param string $player   Player who will be banned
         * @param string $reason   Reason why the player is banned
         * @param integer $time    How long the player is banned (0 = permanent)
         */
        public function add_ban($player, $reason = "Banned", $time = 0)
        {
            $this->send("addBan ".$player." ".$time." ".$reason);
        }


        /**
         * Removes a ban
         *
         * @param integer $banid  Ban who will be removed
         */
        public function remove_ban($banid)
        {
            $this->send("removeBan ".$banid);
        }


        /**
         * Removes expired bans from bans file
         */
        public function write_bans()
        {
            $this->send("writeBans");
        }
    }



    /*
     * Defines some custom Exceptions
     */
    class PacketException extends \Exception {}
    class SocketException extends \Exception {}
    class AuthorizationException extends \Exception {}

}
