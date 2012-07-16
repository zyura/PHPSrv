<?
	class ETcpServer extends Exception { }

	class TcpServer {
		const ECONNABORTED = 103;   /* Software caused connection abort */
		const ECONNRESET   = 104;   /* Connection reset by peer */

		private static $disconnect_errors = array(self::ECONNRESET, self::ECONNABORTED);

		private $server_port = 8080;
		private $server_ip = '127.0.0.1';
		private $server_sock;

		private $client_timeout = 300;
		private $clients = array();
		private $clients_data = array();

		private function error($msg) {
			throw new ETcpServer($msg);
		}

		private function log($msg) {
			echo $msg, "\n";
		}

		function init() {
			$this->server_sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			if (!$this->server_sock) die('Error creating HTTP socket.');
			socket_set_nonblock($this->server_sock);

			if (!socket_bind($this->server_sock, $this->server_ip, $this->server_port))
				return $this->error("Cannot bind to address: $this->server_ip:$this->server_port");
			if (!socket_listen($this->server_sock))
				return $this->error("Cannot listen at address: $this->server_ip:$this->server_port");
			$this->log("HTTP Listening at $this->server_ip:$this->server_port");

			return true;
		}

		function close_client($client_id) {
			$client = $this->clients[$client_id];
			if (is_resource($client))
				socket_close($client);
			unset($this->clients[$client_id]);
			unset($this->clients_data[$client_id]);
		}

		function send($client, $data, $length) {
			$bytes_sent = 0;
			while ($length > 0) {
				if ($bytes_sent) $data = substr($data, $bytes_sent);
				$bytes_sent = socket_write($client, $data, $length);
				if (!$bytes_sent) break;
				$length -= $bytes_sent;
			}
		}

		function step() {
			while ($client = @socket_accept($this->server_sock)) {
				$this->log('New client connected.');

				$this->clients[] = $client;
				socket_write($client, "Hello, client!\r\n");
				$had_events = true;
			}

			if (empty($this->clients))
				return false;

			$read = $this->clients;
			$write = $except = null;

			$changed = socket_select($read, $write, $except, 0);
			if ($changed === false)
				return $this->error('Select error: ' . socket_strerror(socket_last_error()));
			if (!$changed)
				return $had_events;

			foreach ($read as $client) {
				$client_id = array_search($client, $this->clients);
				if ($client_id === false) continue;

				/* socket_read() should return FALSE when client disconnects, but it never does */
				$data = @socket_read($client, 4096);
				if ($data === false) {
					$error = socket_last_error($client);
					if (in_array($error, self::$disconnect_errors))
						$this->close_client($client_id);
					continue;
				}

				$length = strlen($data);

				/* bug workaround - disconnect client after a certain time of inactivity ... */
				$client_data = &$this->clients_data[$client_id];
				if (!is_array($client_data)) $client_data = array();

				if ($length > 0)
					$client_data['time'] = time();
				elseif (
					isset($client_data['time']) &&
					(time() - $client_data['time'] > $this->client_timeout)
				) {
					$this->close_client($client_id);
					continue;
				}
				/* ... end workaround */

				if ($length > 0) {
					$had_events = true;
					$this->send($client, $data, $length);
				}
			}

			return $had_events;
		}

		function run() {
			if (!$this->init())
				return false;

			while (true) {
				if (!$this->step())
					usleep(1000);
				/* do some other stuff */
			}
		}

	}

	$server = new TcpServer();
	$server->run();