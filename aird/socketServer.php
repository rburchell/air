<?
abstract class socketServer extends SocketBase
{
	protected $client_class;

	public function __construct($client_class, $bind_address = 0, $bind_port = 0, $domain = AF_INET, $type = SOCK_STREAM, $protocol = SOL_TCP)
	{
		AirD::Log(AirD::LOGTYPE_INTERNAL, "socketServer's constructor", true);
		parent::__construct($bind_address, $bind_port, $domain, $type, $protocol);
		$this->client_class = $client_class;
		$this->listen();
	}

	public function read()
	{
		AirD::Log(AirD::LOGTYPE_INTERNAL, "socketServer's read", true);
		$client = new $this->client_class(parent::accept());
		$this->on_accept($client);
		return $client;
	}

	// override if desired
	public function on_accept(socketServerClient $client) {}
}
