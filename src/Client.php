<?php

namespace RouterOS;

use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\QueryException;
use RouterOS\Helpers\ArrayHelper;

/**
 * Class Client for RouterOS management
 *
 * @package RouterOS
 * @since   0.1
 */
class Client implements Interfaces\ClientInterface
{
    use SocketTrait;

    /**
     * Socket resource
     *
     * @var resource|null
     */
    private $_socket;

    /**
     * Code of error
     *
     * @var int
     */
    private $_socket_err_num;

    /**
     * Description of socket error
     *
     * @var string
     */
    private $_socket_err_str;

    /**
     * Configuration of connection
     *
     * @var \RouterOS\Config
     */
    private $_config;

    /**
     * API communication object
     *
     * @var APIStream
     */

    private $stream;

    /**
     * Client constructor.
     *
     * @param   array|\RouterOS\Config $config
     * @throws  \RouterOS\Exceptions\ClientException
     * @throws  \RouterOS\Exceptions\ConfigException
     * @throws  \RouterOS\Exceptions\QueryException
     */
    public function __construct($config)
    {
        // If array then need create object
        if (\is_array($config)) {
            $config = new Config($config);
        }

        // Check for important keys
        if (true !== $key = ArrayHelper::checkIfKeysNotExist(['host', 'user', 'pass'], $config->getParameters())) {
            throw new ConfigException("One or few parameters '$key' of Config is not set or empty");
        }

        // Save config if everything is okay
        $this->setConfig($config);

        // Throw error if cannot to connect
        if (false === $this->connect()) {
            throw new ClientException('Unable to connect to ' . $config->get('host') . ':' . $config->get('port'));
        }

        $this->stream = new APIStream($this->getSocket());
    }

    /**
     * Get some parameter from config
     *
     * @param   string $parameter Name of required parameter
     * @return  mixed
     * @throws  \RouterOS\Exceptions\ConfigException
     */
    private function config(string $parameter)
    {
        return $this->_config->get($parameter);
    }

    /**
     * Return socket resource if is exist
     *
     * @return  \RouterOS\Config
     * @since   0.6
     */
    public function getConfig(): Config
    {
        return $this->_config;
    }

    /**
     * Set configuration of client
     *
     * @param   \RouterOS\Config $config
     * @since   0.7
     */
    public function setConfig(Config $config)
    {
        $this->_config = $config;
    }

    /**
     * Send write query to RouterOS (with or without tag)
     *
     * @param   string|array|\RouterOS\Query $query
     * @return  \RouterOS\Client
     * @throws  \RouterOS\Exceptions\ClientException
     * @throws  \RouterOS\Exceptions\QueryException
     */
    public function write($query): Client
    {
        if (\is_string($query)) {
            $query = new Query($query);
        } elseif (\is_array($query)) {
            $endpoint = array_shift($query);
            $query    = new Query($endpoint, $query);
        }

        if (!$query instanceof Query) {
            throw new QueryException('Parameters cannot be processed');
        }

        // Send commands via loop to API
        foreach ($query->getQuery() as $command) {
            $this->stream->writeWord(trim($command));
        }

        // Write empty word terminator
        $this->stream->writeWord('');

        return $this;
    }

    /**
     * Read answer from server after query was executed
     *
     * A Mikrotik reply is formed of blocks
     * Each block starts with a word, one of ('!re', '!trap', '!done', '!fatal')
     * Each block end with an zero byte (empty line)
     * Reply ends with a complete !done or !fatal block (ended with 'empty line')
     * A !fatal block precedes TCP connexion close
     * 
     * @param   bool $parse
     * @return  array
     */
    public function read(bool $parse = true): array
    {
        // By default response is empty
        $response = [];
        // We have to wait a !done or !fatal 
        $lastReply = false;

        // Read answer from API in loop
        while (true) {
            $word = $this->stream->readWord();

            if (''==$word) {
                if ($lastReply) {
                    // We received a !done or !fatal message in a precedent loop
                    // response is complete
                    break;
                }
                // We did not receive the !done or !fatal message
                // This 0 length message is the end of a reply !re or !trap
                // We have to wait the router to send a !done or !fatal reply followed by optionals values and a 0 length message
                continue;
            }

            // Save output line to response array
            $response[] = $word;

            // If we get a !done or !fatal line in response, we are now ready to finish the read
            // but we need to wait a 0 length message, switch the flag
            if ('!done' === $word || '!fatal' === $word) {
                $lastReply = true;
            }
        }

        // Parse results and return
        return $parse ? $this->parseResponse($response) : $response;
    }

    /**
     * Alias for ->write() method
     *
     * @param   string|array|\RouterOS\Query $query
     * @return  \RouterOS\Client
     * @throws  \RouterOS\Exceptions\ClientException
     * @throws  \RouterOS\Exceptions\QueryException
     */
    public function w($query): Client
    {
        return $this->write($query);
    }

    /**
     * Alias for ->read() method
     *
     * @param   bool $parse
     * @return  array
     * @since   0.7
     */
    public function r(bool $parse = true): array
    {
        return $this->read($parse);
    }

    /**
     * Alias for ->write()->read() combination of methods
     *
     * @param   string|array|\RouterOS\Query $query
     * @param   bool                         $parse
     * @return  array
     * @throws  \RouterOS\Exceptions\ClientException
     * @throws  \RouterOS\Exceptions\QueryException
     * @since   0.6
     */
    public function wr($query, bool $parse = true): array
    {
        return $this->write($query)->read($parse);
    }

    /**
     * Parse response from Router OS
     *
     * @param   array $response Response data
     * @return  array Array with parsed data
     */
    private function parseResponse(array $response): array
    {
        $result = [];
        $i      = -1;
        $lines  = \count($response);
        foreach ($response as $key => $value) {
            switch ($value) {
                case '!re':
                    $i++;
                    break;
                case '!fatal':
                    $result = $response;
                    break 2;
                case '!trap':
                case '!done':
                    // Check for =ret=, .tag and any other following messages
                    for ($j = $key + 1; $j <= $lines; $j++) {
                        // If we have lines after current one
                        if (isset($response[$j])) {
                            $this->pregResponse($response[$j], $matches);
                            if (!empty($matches)) {
                                $result['after'][$matches[1][0]] = $matches[2][0];
                            }
                        }
                    }
                    break 2;
                default:
                    $this->pregResponse($value, $matches);
                    if (!empty($matches)) {
                        $result[$i][$matches[1][0]] = $matches[2][0];
                    }
                    break;
            }
        }
        return $result;
    }

    /**
     * Parse result from RouterOS by regular expression
     *
     * @param   string $value
     * @param   array  $matches
     */
    private function pregResponse(string $value, &$matches)
    {
        preg_match_all('/^[=|\.](.*)=(.*)/', $value, $matches);
    }

    /**
     * Authorization logic
     *
     * @param   bool $legacyRetry Retry login if we detect legacy version of RouterOS
     * @return  bool
     * @throws  \RouterOS\Exceptions\ClientException
     * @throws  \RouterOS\Exceptions\ConfigException
     * @throws  \RouterOS\Exceptions\QueryException
     */
    private function login(bool $legacyRetry = false): bool
    {
        // If legacy login scheme is enabled
        if ($this->config('legacy')) {
            // For the first we need get hash with salt
            $query    = new Query('/login');
            $response = $this->write($query)->read();

            // Now need use this hash for authorization
            $query = (new Query('/login'))
                ->add('=name=' . $this->config('user'))
                ->add('=response=00' . md5(\chr(0) . $this->config('pass') . pack('H*', $response['after']['ret'])));
        } else {
            // Just login with our credentials
            $query = (new Query('/login'))
                ->add('=name=' . $this->config('user'))
                ->add('=password=' . $this->config('pass'));

            // If we set modern auth scheme but router with legacy firmware then need to retry query,
            // but need to prevent endless loop
            $legacyRetry = true;
        }

        // Execute query and get response
        $response = $this->write($query)->read(false);

        // if:
        //  - we have more than one response
        //  - response is '!done'
        // => problem with legacy version, swap it and retry
        // Only tested with ROS pre 6.43, will test with post 6.43 => this could make legacy parameter obsolete?
        if ($legacyRetry && $this->isLegacy($response)) {
            $this->_config->set('legacy', true);
            return $this->login();
        }

        // Return true if we have only one line from server and this line is !done
        return (1 === count($response)) && isset($response[0]) && ($response[0] === '!done');
    }

    /**
     * Detect by login request if firmware is legacy
     *
     * @param   array $response
     * @return  bool
     * @throws  ConfigException
     */
    private function isLegacy(array &$response): bool
    {
        return \count($response) > 1 && $response[0] === '!done' && !$this->config('legacy');
    }

    /**
     * Connect to socket server
     *
     * @return  bool
     * @throws  \RouterOS\Exceptions\ClientException
     * @throws  \RouterOS\Exceptions\ConfigException
     * @throws  \RouterOS\Exceptions\QueryException
     */
    private function connect(): bool
    {
        // By default we not connected
        $connected = false;

        // Few attempts in loop
        for ($attempt = 1; $attempt <= $this->config('attempts'); $attempt++) {

            // Initiate socket session
            $this->openSocket();

            // If socket is active
            if (null !== $this->getSocket()) {

                // If we logged in then exit from loop
                if (true === $this->login()) {
                    $connected = true;
                    break;
                }

                // Else close socket and start from begin
                $this->closeSocket();
            }

            // Sleep some time between tries
            sleep($this->config('delay'));
        }

        // Return status of connection
        return $connected;
    }

}
