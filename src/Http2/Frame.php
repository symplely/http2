<?php

/*
 * This file is part of Concurrent PHP HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Concurrent\Http\Http2;

/**
 * The smallest unit of communication within an HTTP/2 connection, consisting of a header and a variable-length sequence
 * of octets structured according to the frame type.
 *
 * @link https://httpwg.github.io/specs/rfc7540.html
 *
 * @author Martin Schröder
 */
class Frame
{
    /**
     * DATA frames (type=0x0) convey arbitrary, variable-length sequences of octets associated with a stream. One or more DATA frames
     * are used, for instance, to carry HTTP request or response payloads.
     *
     * DATA frames MAY also contain padding. Padding can be added to DATA frames to obscure the size of messages.
     * Padding is a security feature; see Section 10.7.
     */
    public const DATA = 0x00;

    /**
     * The HEADERS frame (type=0x1) is used to open a stream (Section 5.1), and additionally carries a header block fragment.
     * HEADERS frames can be sent on a stream in the "idle", "reserved (local)", "open", or "half-closed (remote)" state.
     */
    public const HEADERS = 0x01;

    /**
     * The PRIORITY frame (type=0x2) specifies the sender-advised priority of a stream (Section 5.3). It can be sent in any
     * stream state, including idle or closed streams.
     */
    public const PRIORITY = 0x02;

    /**
     * The RST_STREAM frame (type=0x3) allows for immediate termination of a stream. RST_STREAM is sent to request cancellation
     * of a stream or to indicate that an error condition has occurred.
     */
    public const RST_STREAM = 0x03;

    /**
     * The SETTINGS frame (type=0x4) conveys configuration parameters that affect how endpoints communicate, such as preferences
     * and constraints on peer behavior. The SETTINGS frame is also used to acknowledge the receipt of those parameters.
     * Individually, a SETTINGS parameter can also be referred to as a "setting".
     */
    public const SETTINGS = 0x04;

    /**
     * The PUSH_PROMISE frame (type=0x5) is used to notify the peer endpoint in advance of streams the sender intends to initiate.
     * The PUSH_PROMISE frame includes the unsigned 31-bit identifier of the stream the endpoint plans to create along with a set of
     * headers that provide additional context for the stream. Section 8.2 contains a thorough description of the use of PUSH_PROMISE frames.
     */
    public const PUSH_PROMISE = 0x05;

    /**
     * The PING frame (type=0x6) is a mechanism for measuring a minimal round-trip time from the sender, as well as determining whether
     * an idle connection is still functional. PING frames can be sent from any endpoint.
     */
    public const PING = 0x06;

    /**
     * The GOAWAY frame (type=0x7) is used to initiate shutdown of a connection or to signal serious error conditions.
     * GOAWAY allows an endpoint to gracefully stop accepting new streams while still finishing processing of previously
     * established streams. This enables administrative actions, like server maintenance.
     */
    public const GOAWAY = 0x07;

    /**
     * The WINDOW_UPDATE frame (type=0x8) is used to implement flow control; see Section 5.2 for an overview.
     */
    public const WINDOW_UPDATE = 0x08;

    /**
     * The CONTINUATION frame (type=0x9) is used to continue a sequence of header block fragments (Section 4.3).
     * Any number of CONTINUATION frames can be sent, as long as the preceding frame is on the same stream and is a HEADERS,
     * PUSH_PROMISE, or CONTINUATION frame without the END_HEADERS flag set.
     */
    public const CONTINUATION = 0x09;

    /**
     * No flags.
     */
    public const NOFLAG = 0x00;

    /**
     * Acknowledged frame.
     */
    public const ACK = 0x01;

    /**
     * When set, bit 0 indicates that this frame is the last that the endpoint will send for the identified stream. Setting this flag
     * causes the stream to enter one of the "half-closed" states or the "closed" state (Section 5.1).
     */
    public const END_STREAM = 0x01;

    /**
     * When set, bit 2 indicates that this frame contains an entire header block (Section 4.3) and is not followed by any CONTINUATION frames.
     */
    public const END_HEADERS = 0x04;

    /**
     * When set, bit 3 indicates that the Pad Length field and any padding that it describes are present.
     */
    public const PADDED = 0x08;

    /**
     * When set, bit 5 indicates that the Exclusive Flag (E), Stream Dependency, and Weight fields are present; see Section 5.3.
     */
    public const PRIORITY_FLAG = 0x20;

    /**
     * The associated condition is not a result of an error. For example, a GOAWAY might include this code to indicate graceful shutdown of a connection.
     */
    public const NO_ERROR = 0x00;

    /**
     * The endpoint detected an unspecific protocol error. This error is for use when a more specific error code is not available.
     */
    public const PROTOCOL_ERROR = 0x01;

    /**
     * The endpoint encountered an unexpected internal error.
     */
    public const INTERNAL_ERROR = 0x02;

    /**
     * The endpoint detected that its peer violated the flow-control protocol.
     */
    public const FLOW_CONTROL_ERROR = 0x03;

    /**
     * The endpoint sent a SETTINGS frame but did not receive a response in a timely manner. See Section 6.5.3 ("Settings Synchronization").
     */
    public const SETTINGS_TIMEOUT = 0x04;

    /**
     * The endpoint received a frame after a stream was half-closed.
     */
    public const STREAM_CLOSED = 0x05;

    /**
     * The endpoint received a frame with an invalid size.
     */
    public const FRAME_SIZE_ERROR = 0x06;

    /**
     * The endpoint refused the stream prior to performing any application processing (see Section 8.1.4 for details).
     */
    public const REFUSED_STREAM = 0x07;

    /**
     * Used by the endpoint to indicate that the stream is no longer needed.
     */
    public const CANCEL = 0x08;

    /**
     * The endpoint is unable to maintain the header compression context for the connection.
     */
    public const COMPRESSION_ERROR = 0x09;

    /**
     * The connection established in response to a CONNECT request (Section 8.3) was reset or abnormally closed.
     */
    public const CONNECT_ERROR = 0x0A;

    /**
     * The endpoint detected that its peer is exhibiting a behavior that might be generating excessive load.
     */
    public const ENHANCE_YOUR_CALM = 0x0B;

    /**
     * The underlying transport has properties that do not meet minimum security requirements (see Section 9.2).
     */
    public const INADEQUATE_SECURITY = 0x0C;

    /**
     * The endpoint requires that HTTP/1.1 be used instead of HTTP/2.
     */
    public const HTTP_1_1_REQUIRED = 0x0D;

    /**
     * Frame type.
     *
     * @var int
     */
    public $type;

    /**
     * Stream identifier (0 for connection).
     * 
     * @var int
     */
    public $stream;
    
    /**
     * Frame flags.
     *
     * @var int
     */
    public $flags;

    /**
     * Payload of the frame.
     *
     * @var string
     */
    public $data;

    /**
     * Create a new HTTP/2 frame.
     *
     * @param int $type
     * @param string $data
     * @param int $flags
     */
    public function __construct(int $type, int $stream, string $data, int $flags = self::NOFLAG)
    {
        $this->type = $type;
        $this->stream = $stream;
        $this->data = $data;
        $this->flags = $flags;
    }
    
    public function __debugInfo(): array
    {
        return [
            'type' => $this->getTypeName(),
            'flags' => $this->flags,
            'stream' => $this->stream,
            'length' => \strlen($this->data)
        ];
    }

    /**
     * Convert frame into a human-readable form.
     */
    public function __toString(): string
    {
        $info = ($this->type == self::WINDOW_UPDATE) ? \unpack('N', $this->data)[1] : (\strlen($this->data) . ' bytes');
        
        return \sprintf("%s [%b] <%u> %s", $this->getTypeName(), $this->flags, $this->stream, $info);
    }
    
    /**
     * Get a human-readable label that represents the frame type.
     */
    public function getTypeName(): string
    {
        switch ($this->type) {
            case self::CONTINUATION:
                return 'CONTINUATION';
            case self::DATA:
                return 'DATA';
            case self::GOAWAY:
                return 'GOAWAY';
            case self::HEADERS:
                return 'HEADERS';
            case self::PING:
                return 'PING';
            case self::PRIORITY:
                return 'PRIORITY';
            case self::PUSH_PROMISE:
                return 'PUSH_PROMISE';
            case self::RST_STREAM:
                return 'RST_STREAM';
            case self::SETTINGS:
                return 'SETTINGS';
            case self::WINDOW_UPDATE:
                return 'WINDOW_UPDATE';
        }
        
        return 'UNKNOWN';
    }

    /**
     * Encode the frame into it's binary form for transmission.
     *
     * @return string
     */
    public function encode(): string
    {
        return \substr(\pack('NccN', \strlen($this->data), $this->type, $this->flags, $this->stream), 1) . $this->data;
    }

    /**
     * Get frame payload (data with padding removed).
     *
     * @return string
     */
    public function getPayload(): string
    {
        if ($this->flags & self::PADDED) {
            return \substr($this->data, 1, -1 * \ord($this->data[0]));
        }
        
        return $this->data;
    }
}
