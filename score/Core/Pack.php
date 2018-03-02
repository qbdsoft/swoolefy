<?php 
namespace Swoolefy\Core;

class Pack {

	/**
	 * $server 
	 * @var null
	 */
	public $server = null;

	/**
	 * $_buffer 对于一个连接没有收到完整数据包的数据进行暂时的缓存，等待此链接的数据包接收完整在处理
	 * @var array
	 */
	protected $_buffers = [];

	/**
	 * $_header 保存一个不完整包的包头数据
	 * @var array
	 */
	protected $_headers = [];

	/**
	 * $_pack_size 包的大小，实际应用应设置与package_max_length设置保持一致，默认2M
	 * @var [type]
	 */
	public $_packet_maxlen = 2 * 1024 * 1024;

	/**
	 * $_header_struct 析包结构,包含包头结构，key代表的是包头的字段，value代表的pack的类型
	 * @var string
	 */
	public static $_header_struct = ['length'=>'N','name'=>'a30'];

	/**
	 * $pack_length_type pack包数据的类型, 默认null
	 * @var null
	 */
	protected static $pack_length_type = null;


	/**
	 * $unpack_length_type unpack包数据的类型, 默认null
	 * @var null
	 */
	protected static $unpack_length_type = null;

	/**
	 * $pack_length_key 包的长度设定的key，与$_header_struct里设置的长度的key一致
	 * @var string
	 */
	public static $pack_length_key = 'length';

	/**
	 * $serialize_type 设置数据序列化的方式 
	 * @var [type]
	 */
	public $serialize_type = 'json';

	/**
	 * 定义序列化的方式
	 */
	const SERIALIZE_TYPE = [
		'json' => 1,
		'serialize' => 2,
		'swoole' => 3
	];

	const DECODE_JSON = 1;
    const DECODE_PHP = 2;
    const DECODE_SWOOLE = 3;

    const ERR_HEADER            = 9001;   //错误的包头
    const ERR_TOOBIG            = 9002;   //请求包体长度超过允许的范围
    const ERR_SERVER_BUSY       = 9003;   //服务器繁忙，超过处理能力
    
	/**
	 * $_header_size 包头固定大小
	 * @var integer
	 */
	public static $_header_size = 34;

	/**
	 * $_pack_eof eof分包时设置
	 * @var string
	 */
	public static $_pack_eof = "\r\n\r\n";

	/**
	 * __construct 
	 * @param    \Swoole\Server $server
	 */
	public function __construct(\Swoole\Server $server) {
		$this->server = $server;
	}

	/**
	 * decodePack 接收数据解包处理
	 * usages:
	 	Pack::$_header_struct = ['length'=>'N','name'=>'a30']  包头定义的字段与对应类型
	 	Pack::$pack_length_key = 'length'   包头的记录包体长度的key,要与$_header_struct的key一致
	 	Pack::$_header_size = 34            固定包头字节大小
		$Pack = new Pack();
		$Pack->serialize_type = Pack::DECODE_SWOOLE;
		$Pack->_packet_maxlen = 2 * 1024 * 1024 包的最大长度，与package_max_length设置保持一致
		$res = $Pack->depack($server, $fd, $reactor_id, $data);

	 * @param    \Swoole\Server $server
	 * @param    int            $fd
	 * @param    int         $reactor_id
	 * @param    string         $data
	 * @return   mixed
	 */
	public function depack(\Swoole\Server $server, $fd, $reactor_id, $data) {
		//接收的包数据不完整的
		if(isset($this->_buffers[$fd])) {
			// 每次再接收的数据是属于上一个不完整的包的，已经没有包头了，直接包体数据
			$this->_buffers[$fd] .= $data;

			$buffer_fd_data_length = strlen($this->_buffers[$fd]);

			// 长度错误时
			if($buffer_fd_data_length > $this->_packet_maxlen) {
				$this->sendErrorMessage($fd, self::ERR_TOOBIG);
				unset($this->_buffers[$fd], $this->_headers[$fd]);
				return false;
			}

			if($buffer_fd_data_length < $this->_headers[$fd][self::$pack_length_key]) {
				return false;
			}else {
				// 数据包已接收完整
				$pack_body = $this->_buffers[$fd];
				// 数据解包
				$data = $this->decode($pack_body, $this->serialize_type);

				$request = [$this->_headers[$fd], $data];
				unset($this->_buffers[$fd], $this->_headers[$fd]);
				// 返回包头和包体数据
				return $request;
			}

		}else {
			// 设置unpack包类型
			$this->setUnpackLengthType();
			// 解析包头
			$header = unpack(self::$unpack_length_type, mb_strcut($data, 0, self::$_header_size, 'UTF-8'));

			$this->filterHeader($header);
			// 包头包含的包体长度值
			$length = $header[self::$pack_length_key];

			$header['fd'] = $fd;
			$this->_headers[$fd] = $header;

			$pack_body = mb_strcut($data, self::$_header_size, null, 'UTF-8');
			// 接收数据包完整
			if(strlen($pack_body) == $length) {
				// 数据解包
				$data = $this->decode($pack_body, $this->serialize_type);

				$request = [$this->_headers[$fd], $data];
				unset($this->_buffers[$fd], $this->_headers[$fd]);
				// 返回包头和包体数据
				return $request;
			}else {
				// 包数据不完整，先缓存
				$this->_buffers[$fd] .= $pack_body;
				return false;
			}
		}
		
	}

	/**
	 * setPackLengthType  设置pack头的类型
	 * @return   string 
	 */
	public function setPackLengthType(array $struct=[]) {
		if(self::$pack_length_type) {
			return self::$pack_length_type;
		}

		$pack_length_type = '';
		if(self::$_header_struct) {
			foreach(self::$_header_struct as $key=>$value) {
				$pack_length_type .= $value;
			}
		}
		// 赋值
		self::$pack_length_type = $pack_length_type;
		return $pack_length_type;
	}

	/**
	 * setPackLengthType  设置unpack头的类型
	 * @return   string 
	 */
	public function setUnpackLengthType(array $struct=[]) {
		if(self::$unpack_length_type) {
			return self::$unpack_length_type;
		}

		$pack_length_type = '';
		if(self::$_header_struct) {
			foreach(self::$_header_struct as $key=>$value) {
				$pack_length_type .= ($value.$key).'/';
			}
		}
		// 赋值
		self::$unpack_length_type = trim($pack_length_type, '/');
		return $pack_length_type;
	}

	/**
	 * sendErrorMessage 
	 * @param    int  $fd
	 * @param    mixed  $errno
	 * @return   boolean
	 */
	public function sendErrorMessage($fd, $errno) {
        return $this->server->send($fd, self::encode(['errcode' => $errno,'msg'=>'packet length more than packet_maxlen'], $this->serialize_type));
    }

    /**
     * filterHeader  去掉头部空格|null
     * @param    &$header 
     * @return   array
     */
	public function filterHeader(&$header) {
		foreach($header as $key=>&$value) {
			$header[$key] = trim($value);
		}

		return $header;
	}

	/**
	 * encode 数据封包
	 * usages:
	 	$header = ['length'=>'','name'=>'bingcool'];头部字段信息,'length'字段可以为空
		$Pack = new Pack();
		$sendData = $Pack->enpack($data, $header, Pack::DECODE_SWOOLE);

	 * @param    mixed  $data
	 * @param    array  $header
	 * @param    string  $seralize_type
	 * @return   string
	 */
	public static function enpack($data, $header, $seralize_type = self::DECODE_JSON) {
		$body = self::encode($data, $seralize_type);
        $bin_header_data = '';
        
        foreach(self::$_header_struct as $key=>$value) {
        	// 计算包体长度
        	if($key == self::$pack_length_key) {
        		$bin_header_data .= pack($value, strlen($body));
        	}else {
        		// 其他的包头
        		$bin_header_data .= pack($value, $header[$key]);
        	}
        	 
        }

        return $bin_header_data . $body;
	}

	/**
	 * encode 数据序列化
	 * @param   mixed   $data
	 * @param   int     $seralize_type
	 * @return  string
	 */
	public static function encode($data, $seralize_type = self::DECODE_JSON) {
		if(is_string($seralize_type)) {
			$seralize_type = self::SERIALIZE_TYPE[$seralize_type];
		}
        switch($seralize_type) {
        		// json
            case 1:
                return json_encode($data);
                // serialize
            case 2:
            	return serialize($data);
            case 3;
            default:
            	// swoole
            	return swoole_pack($data);  
        }
	}

	/**
	 * decode 数据反序列化
	 * @param    string   $data
	 * @param    mixed    $unseralize_type
	 * @return   mixed
	 */
	public static function decode($data, $unseralize_type = self::DECODE_JSON) {
		if(is_string($unseralize_type)) {
			$unseralize_type = self::SERIALIZE_TYPE[$unseralize_type];
		}
        switch($unseralize_type) {
        		// json
            case 1:
                return json_decode($data, true);
                // serialize
            case 2:
            	return unserialize($data);
            case 3;
            default:
            	// swoole
            	return swoole_unpack($data);   
        }
    }

    /**
     * delete 删除缓存的不完整的僵尸式数据包，可以在onclose回调中执行,防止内存偷偷溢增
     * @return void
     */
    public function delete($fd) {
    	unset($this->_buffers[$fd], $this->_headers[$fd]);
    	return;
    }

    /**
     * destroy 当workerstop时,删除缓冲的不完整的僵尸式数据包，并强制断开这些链接
     * @return void
     */
    public function destroy($server = null, $worker_id = null) {
    	if(!empty($this->_buffers)) {
    		foreach($this->_buffers as $fd=>$data) {
    			$this->server->close($fd, true);
    			unset($this->_buffers[$fd], $this->_headers[$fd]);
    		}
    		return;	
    	}
    }

    /**
     * enpackeof eof协议封包,包体中不能含有eof的结尾符号，否则出错
     * usages:
     	Pack::$_pack_eof = "\r\n\r\n";
		$Pack = new Pack();
		$sendData = $Pack->enpackeof($data, Pack::DECODE_JSON);
					
     * @param  mixed $data
     * @param  int   $seralize_type
     * @param  string $eof
     * @return string
     */
    public function enpackeof($data, $seralize_type = self::DECODE_JSON, $eof ='') {
    	if(empty($eof)) {
    		$eof = self::$_pack_eof;
    	}
    	$data = self::encode($data, $seralize_type).$eof;
    	
    	return $data;
    }

    /**
     * depackeof  eof协议解包,每次收到一个完整的包
     * usages:
    	Pack::$_pack_eof = "\r\n\r\n";
		$Pack = new Pack();
		$res = $Pack->depackeof($data, Pack::DECODE_JSON);
     
     * @param   string  $data
     * @param   int     $unseralize_type
     * @return  mixed 
     */
    public function depackeof($data, $unseralize_type = self::DECODE_JSON) {
    	return self::decode($data, $unseralize_type);
    }

}
