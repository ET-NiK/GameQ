<?php
/**
 * This file is part of GameQ.
 *
 * GameQ is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * GameQ is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace GameQ;

use GameQ\Exception\Server as Exception;

/**
 * Server class to represent each server entity
 *
 * @author Austin Bischoff <austin@codebeard.com>
 */
class Server
{

    /*
     * Server array keys
     */
    const SERVER_TYPE    = 'type';

    const SERVER_HOST    = 'host';

    const SERVER_ID      = 'id';

    const SERVER_OPTIONS = 'options';

    /*
     * Server options keys
     */
    const SERVER_OPTIONS_QUERY_PORT         = 'query_port';

    const SERVER_OPTIONS_MASTER_SERVER_PORT = 'master_server_port';

    /**
     * The protocol class for this server
     *
     * @type null|\GameQ\Protocol
     */
    protected $protocol = null;

    /**
     * Id of this server
     *
     * @type string
     */
    public $id = null;

    /**
     * IP Address of this server
     *
     * @type string
     */
    public $ip = null;

    /**
     * The server's client port (connect port)
     *
     * @type int|null
     */
    public $port_client = null;

    /**
     * The server's query port
     *
     * @type int|null
     */
    public $port_query = null;

    /**
     * Holds other server specific options
     *
     * @type array
     */
    protected $options = [ ];

    /**
     * Holds the sockets already open for this server
     *
     * @type array
     */
    protected $sockets = [ ];

    /**
     * Construct the class with the passed options
     *
     * @param array $server_info
     *
     * @throws \GameQ\Exception\Server
     */
    public function __construct(Array $server_info)
    {

        // Check for server type
        if (!array_key_exists(self::SERVER_TYPE, $server_info) || empty($server_info[self::SERVER_TYPE])) {
            throw new Exception("Missing server info key '" . self::SERVER_TYPE . "'");
        }

        // Check for server host
        if (!array_key_exists(self::SERVER_HOST, $server_info) || empty($server_info[self::SERVER_HOST])) {
            throw new Exception("Missing server info key '" . self::SERVER_HOST . "'");
        }

        // Check for options
        if (!array_key_exists(self::SERVER_OPTIONS, $server_info)
            || !is_array($server_info[self::SERVER_OPTIONS])
            || empty($server_info[self::SERVER_OPTIONS])
        ) {
            // Default the options to an empty array
            $server_info[self::SERVER_OPTIONS] = [ ];
        }

        $this->options = $server_info[self::SERVER_OPTIONS];

        // We have an IPv6 address (and maybe a port)
        if (substr_count($server_info[self::SERVER_HOST], ':') > 1) {
            // See if we have a port, input should be in the format [::1]:27015 or similar
            if (strstr($server_info[self::SERVER_HOST], ']:')) {
                // Explode to get port
                $server_addr = explode(':', $server_info[self::SERVER_HOST]);

                // Port is the last item in the array, remove it and save
                $this->port_client = (int) array_pop($server_addr);

                // The rest is the address, recombine
                $this->ip = implode(':', $server_addr);

                unset($server_addr);
            } else {
                // Just the IPv6 address, no port defined
                $this->ip = $server_info[self::SERVER_HOST];
            }

            // Now let's validate the IPv6 value sent, remove the square brackets ([]) first
            if (!filter_var(trim($this->ip, '[]'), FILTER_VALIDATE_IP, [
                'flags' => FILTER_FLAG_IPV6,
            ])
            ) {
                throw new Exception("The IPv6 address '{$this->ip}' is invalid.");
            }
        } else {
            // We have a port defined
            if (strstr($server_info[self::SERVER_HOST], ':')) {
                list($this->ip, $this->port_client) = explode(':', $server_info[self::SERVER_HOST]);
            } else {
                // No port, just IPv4
                $this->ip = $server_info[self::SERVER_HOST];
            }

            // Validate the IPv4 value, if FALSE is not a valid IP, maybe a hostname.  Try to resolve
            if (!filter_var($this->ip, FILTER_VALIDATE_IP, [
                'flags' => FILTER_FLAG_IPV4,
            ])
            ) {
                // When gethostbyname() fails it returns the original string
                // so if ip and the result from gethostbyname() are equal this failed.
                if ($this->ip === gethostbyname($this->ip)) {
                    throw new Exception("Unable to resolve the host '{$this->ip}' to an IP address.");
                }
            }
        }


        // Make the protocol class for this type
        $class = new \ReflectionClass(sprintf('GameQ\\Protocols\\%s', ucfirst($server_info[self::SERVER_TYPE])));

        // Set the protocol
        $this->protocol = $class->newInstanceArgs([ $this->options ]);

        // There is an option for the query port, we will do this now
        if (array_key_exists(self::SERVER_OPTIONS_QUERY_PORT, $this->options)
            && !empty($this->options[self::SERVER_OPTIONS_QUERY_PORT])
        ) {
            $this->port_query = (int) $this->options[self::SERVER_OPTIONS_QUERY_PORT];
        } else {
            // Do math based on the protocol class
            $this->port_query = $this->port_client + $this->protocol->port_diff();
        }

        // Check for server id
        if (!array_key_exists(self::SERVER_ID, $server_info) || empty($server_info[self::SERVER_ID])) {
            // Make an id so each server has an id when returned
            $server_info[self::SERVER_ID] = sprintf('%s:%d', $this->ip, $this->port_client);
        }

        // Set the server id
        $this->id = $server_info[self::SERVER_ID];

        unset($server_info, $class);
    }

    /**
     * Set an option for this server
     *
     * @param $key
     * @param $value
     *
     * @return $this
     */
    public function setOption($key, $value)
    {

        $this->options[$key] = $value;

        return $this; // Make chainable
    }

    /**
     * Return set option value
     *
     * @param mixed $key
     *
     * @return mixed
     */
    public function getOption($key)
    {

        return (array_key_exists($key, $this->options)) ? $this->options[$key] : null;
    }

    /**
     * Get the ID for this server
     *
     * @return string
     */
    public function id()
    {

        return $this->id;
    }

    /**
     * Get the IP address for this server
     *
     * @return string
     */
    public function ip()
    {

        return $this->ip;
    }

    /**
     * Get the client port for this server
     *
     * @return int|null
     */
    public function port_client()
    {

        return $this->port_client;
    }

    /**
     * Get the query port for this server
     *
     * @return int|null
     */
    public function port_query()
    {

        return $this->port_query;
    }

    /**
     * Return the protocol class for this server
     *
     * @return \GameQ\Protocol
     */
    public function protocol()
    {

        return $this->protocol;
    }

    /**
     * Get the join link for this server
     *
     * @return string
     */
    public function getJoinLink()
    {

        return sprintf($this->protocol->join_link(), $this->ip, $this->port_client());
    }

    /*
     * Socket holding
     */

    /**
     * Add a socket for this server to be reused
     *
     * @param \GameQ\Query\Core $socket
     */
    public function socketAdd(Query\Core $socket)
    {

        $this->sockets[] = $socket;
    }

    /**
     * Get a socket from the list to reuse, if any are available
     *
     * @return \GameQ\Query\Core|null
     */
    public function socketGet()
    {

        $socket = null;

        if (count($this->sockets) > 0) {
            $socket = array_pop($this->sockets);
        }

        return $socket;
    }

    /**
     * Clear any sockets still listed and attempt to close them
     */
    public function socketCleanse()
    {

        // Close all of the sockets available
        foreach ($this->sockets AS $socket) {
            $socket->close();
        }

        // Reset the sockets list
        $this->sockets = [ ];
    }
}
