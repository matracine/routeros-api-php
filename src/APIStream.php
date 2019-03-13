<?php

namespace RouterOS;

class APIStream
{

    protected $stream;

    public function __construct(resource $stream)
    {
        // TODO  : Should we verify the resource type ?
        $this->stream = $stream;
    }

    /**
     * Reads a WORD from the stream
     *
     * WORDs are part of SENTENCE. Each WORD has to be encoded in certain way - length of the WORD followed by WORD content.
     * Length of the WORD should be given as count of bytes that are going to be sent
     *
     * @return string The word content, en empty string for end of SENTENCE
     */ 
    public function readWord() : string
    {
        // If the first bit is set then we need to remove the first four bits, shift left 8
        // and then read another byte in.
        // We repeat this for the second and third bits.
        // If the fourth bit is set, we need to remove anything left in the first byte
        // and then read in yet another byte.
        // If the first bit is set then we need to remove the first four bits, shift left 8
        // and then read another byte in.
        // We repeat this for the second and third bits.
        // If the fourth bit is set, we need to remove anything left in the first byte
        // and then read in yet another byte.
        $byte = ord(fread($this->stream, 1));
        if ($byte & 128) {
            if (($byte & 192) === 128) {
                $length = (($byte & 63) << 8) + \ord(fread($this->stream, 1));
            } elseif (($byte & 224) === 192) {
                $length = (($byte & 31) << 8) + \ord(fread($this->stream, 1));
                $length = ($length << 8) + \ord(fread($this->stream, 1));
            } elseif (($byte & 240) === 224) {
                $length = (($byte & 15) << 8) + \ord(fread($this->stream, 1));
                $length = ($length << 8) + \ord(fread($this->stream, 1));
                $length = ($length << 8) + \ord(fread($this->stream, 1));
            } else {
                $length = \ord(fread($this->stream, 1));
                $length = ($length << 8) + \ord(fread($this->stream, 1)) * 3;
                $length = ($length << 8) + \ord(fread($this->stream, 1));
                $length = ($length << 8) + \ord(fread($this->stream, 1));
            }
        } else {
            $length = $byte;
        }

        if (0===$length) {
            return '';
        }

        return stream_get_contents($this->_socket, $length);
    }

    static public function encodeWord(string $string): string
    {
        $length = strlen($string);
        if (0==$length) {
            return chr(0);
        }

        if ($length < 128) {
            $orig_length = $length;
            $offset      = -1;
        } elseif ($length < 16384) {
            $orig_length = $length | 0x8000;
            $offset      = -2;
        } elseif ($length < 2097152) {
            $orig_length = $length | 0xC00000;
            $offset      = -3;
        } elseif ($length < 268435456) {
            $orig_length = $length | 0xE0000000;
            $offset      = -4;
        } else {
            throw new APIStreamException("Unable to encode length of '$string'");
        }

        // Pack string to binary format
        $result = pack('I*', $orig_length);
        // Parse binary string to array
        $result = str_split($result);
        // Reverse array
        $result = array_reverse($result);
        // Extract values from offset to end of array
        $result = \array_slice($result, $offset);

        // Sew items into one line
        $output = null;
        foreach ($result as $item) {
            $output .= $item;
        }

        return $output.$string;
    }

    public function writeWord(string $word)
    {
        fwrite($this->stream, $word);
    }
}