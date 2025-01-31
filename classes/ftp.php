<?php
/**
 * Fuel is a fast, lightweight, community driven PHP 5.4+ framework.
 *
 * @package    Fuel
 * @version    1.9-dev
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2019 Fuel Development Team
 * @link       https://fuelphp.com
 */

namespace Fuel\Core;

class FtpConnectionException extends \FuelException {}

class FtpFileAccessException extends \FuelException {}

/**
 * FTP Class
 *
 * @package		Fuel
 * @category	Core
 * @author		Phil Sturgeon
 * @link		http://docs.fuelphp.com/classes/ftp.html
 */
class Ftp
{
	public static $initialized = false;

	protected $_hostname;
	protected $_username;
	protected $_password;
	protected $_port;
	protected $_timeout;
	protected $_passive;
	protected $_ssl_mode;
	protected $_debug;

	protected $_conn_id   = false;

	/**
	 * Returns a new Ftp object. If you do not define the "file" parameter,
	 *
	 *     $ftp = static::forge('group');
	 *
	 * @param   string|array  $config   The name of the config group to use, or a configuration array.
	 * @param   bool          $connect  Automatically connect to this server.
	 * @return  Ftp
	 */
	public static function forge($config = 'default', $connect = true)
	{
		$ftp = new static($config);

		// Unless told not to, connect automatically
		$connect === true and $ftp->connect();

		return $ftp;
	}

	/**
	 * Sets the initial Ftp filename and local data.
	 *
	 * @param   string|array  $config  The name of the config group to use, or a configuration array.
	 */
	public function __construct($config = 'default')
	{
		\Config::load('ftp', true);

		// If it is a string we're looking at a predefined config group
		if (is_string($config))
		{
			$config_arr = \Config::get('ftp.'.$config);

			// Check that it exists
			if ( ! is_array($config_arr) or $config_arr === array())
			{
				throw new \UnexpectedValueException('You have specified an invalid ftp connection group: '.$config);
			}

			$config = $config_arr;
		}

		// fill in defaults if not given
		$config = array_merge(
		    array('hostname' => 'localhost', 'username' => '', 'password' => '', 'port' => 21, 'timeout' => 90, 'passive' => true, 'ssl_mode' => false, 'debug' => false),
		    $config);

		// Prep the hostname
		$this->_hostname = preg_replace('|.+?://|', '', $config['hostname']);
		$this->_username = $config['username'];
		$this->_password = $config['password'];
		$this->_timeout  = (int) $config['timeout'];
		$this->_port     = (int) $config['port'];
		$this->_passive  = (bool) $config['passive'];
		$this->_ssl_mode = (bool) $config['ssl_mode'];
		$this->_debug    = (bool) $config['debug'];

		static::$initialized = true;
	}

	// --------------------------------------------------------------------

	/**
	 * FTP Connect
	 *
	 * @return	\Ftp
	 * @throws	\FtpConnectionException
	 */
	public function connect()
	{
		if($this->_ssl_mode === true)
		{
			if( ! function_exists('ftp_ssl_connect'))
			{
				throw new \RuntimeException('ftp_ssl_connect() function is missing.');
			}

			$this->_conn_id = @ftp_ssl_connect($this->_hostname, $this->_port, $this->_timeout);
		}

		else
		{
			$this->_conn_id = @ftp_connect($this->_hostname, $this->_port, $this->_timeout);
		}

		if ($this->_conn_id === false)
		{
			if ($this->_debug == true)
			{
				throw new \FtpConnectionException('Unable to establish a connection');
			}
			return false;
		}

		if ( ! $this->_login())
		{
			if ($this->_debug == true)
			{
				throw new \FtpConnectionException('Unable to login');
			}
		}

		// Set passive mode if needed
		if ($this->_passive == true)
		{
			ftp_pasv($this->_conn_id, true);
		}

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * FTP Login
	 *
	 * @return	bool
	 */
	protected function _login()
	{
		return @ftp_login($this->_conn_id, $this->_username, $this->_password);
	}

	// --------------------------------------------------------------------

	/**
	 * Validates the connection ID
	 *
	 * @return	bool
	 */
	protected function _is_conn()
	{
		if ( ! is_resource($this->_conn_id) and ! $this->_conn_id instanceOf \FTP\Connection)
		{
			if ($this->_debug == true)
			{
				throw new \InvalidArgumentException('Invalid connection');
			}
			return false;
		}
		return true;
	}

	// --------------------------------------------------------------------


	/**
	 * Change directory
	 *
	 * The second parameter lets us momentarily turn off debugging so that
	 * this function can be used to test for the existence of a folder
	 * without throwing an error.  There's no FTP equivalent to is_dir()
	 * so we do it by trying to change to a particular directory.
	 * Internally, this parameter is only used by the "mirror" function below.
	 *
	 * @param	string $path
	 * @return	bool
	 * @throws \FtpFileAccessException
	 */
	public function change_dir($path = '')
	{
		if ($path == '' or ! $this->_is_conn())
		{
			return false;
		}

		$result = @ftp_chdir($this->_conn_id, $path);

		if ($result === false)
		{
			if ($this->_debug == true)
			{
				throw new \FtpFileAccessException('Unable to change the directory');
			}
			return false;
		}

		return true;
	}

	// --------------------------------------------------------------------

	/**
	 * Create a directory
	 *
	 * @param	string	$path
	 * @param	string	$permissions
	 * @return	bool
	 * @throws \FtpFileAccessException
	 */
	public function mkdir($path, $permissions = null)
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		$result = ftp_mkdir($this->_conn_id, $path);

		if ($result === false)
		{
			if ($this->_debug == true)
			{
				throw new \FtpFileAccessException('Unable to create directory');
			}
			return false;
		}

		// Set file permissions if needed
		if ($permissions !== null)
		{
			$this->chmod($path, (int) $permissions);
		}

		return true;
	}

	// --------------------------------------------------------------------

	/**
	 * Upload a file to the server
	 *
	 * @param	string	$local_path
	 * @param	string	$remote_path
	 * @param	string	$mode
	 * @param	string	$permissions
	 * @return	bool
	 * @throws	\FtpFileAccessException
	 */
	public function upload($local_path, $remote_path, $mode = 'auto', $permissions = null)
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		if ( ! is_file($local_path))
		{
			throw new \FtpFileAccessException('No source file');
		}

		// Set the mode if not specified
		if ($mode == 'auto')
		{
			// Get the file extension so we can set the upload type
			$ext = pathinfo($local_path, PATHINFO_EXTENSION);
			$mode = $this->_settype($ext);
		}

		$mode = ($mode == 'ascii') ? FTP_ASCII : FTP_BINARY;

		$result = @ftp_put($this->_conn_id, $remote_path, $local_path, $mode);

		if ($result === false)
		{
			if ($this->_debug == true)
			{
				throw new \FtpFileAccessException('Unable to upload');
			}
			return false;
		}

		// Set file permissions if needed
		if ($permissions !== null)
		{
			$this->chmod($remote_path, (int) $permissions);
		}

		return true;
	}

	// --------------------------------------------------------------------

	/**
	 * Download a file from a remote server to the local server
	 *
	 * @param	string	$remote_path
	 * @param	string	$local_path
	 * @param	string	$mode
	 * @return	bool
	 * @throws	\FtpFileAccessException
	 */
	public function download($remote_path, $local_path, $mode = 'auto')
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		// Set the mode if not specified
		if ($mode == 'auto')
		{
			// Get the file extension so we can set the upload type
			$ext = pathinfo($remote_path, PATHINFO_BASENAME);
			$mode = $this->_settype($ext);
		}

		$mode = ($mode == 'ascii') ? FTP_ASCII : FTP_BINARY;

		$result = @ftp_get($this->_conn_id, $local_path, $remote_path, $mode);

		if ($result === false)
		{
			if ($this->_debug === true)
			{
				throw new \FtpFileAccessException('Unable to download');
			}
			return false;
		}

		return true;
    }

	// --------------------------------------------------------------------

	/**
	 * Rename (or move) a file
	 *
	 * @param	$old_file	string
	 * @param	$new_file	string
	 * @param	$move		bool
	 * @return	bool
	 * @throws	\FtpFileAccessException
	 */
	public function rename($old_file, $new_file, $move = false)
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		$result = @ftp_rename($this->_conn_id, $old_file, $new_file);

		if ($result === false)
		{
			if ($this->_debug == true)
			{
				$msg = ($move == false) ? 'Unable to rename' : 'Unable to move';

				throw new \FtpFileAccessException($msg);
			}
			return false;
		}

		return true;
	}

	// --------------------------------------------------------------------

	/**
	 * Move a file
	 *
	 * @param	string	$old_file
	 * @param	string	$new_file
	 * @return	bool
	 */
	public function move($old_file, $new_file)
	{
		return $this->rename($old_file, $new_file, true);
	}

	// --------------------------------------------------------------------

	/**
	 * Rename (or move) a file
	 *
	 * @param	string	$filepath
	 * @return	bool
	 * @throws	\FtpFileAccessException
	 */
	function delete_file($filepath)
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		$result = @ftp_delete($this->_conn_id, $filepath);

		if ($result === false)
		{
			if ($this->_debug == true)
			{
				throw new \FtpFileAccessException('Unable to delete');
			}
			return false;
		}

		return true;
	}

	// --------------------------------------------------------------------

	/**
	 * Delete a folder and recursively delete everything (including sub-folders)
	 * contained within it.
	 *
	 * @param	string	$filepath
	 * @return	bool
	 * @throws	\FtpFileAccessException
	 */
	function delete_dir($filepath)
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		// Add a trailing slash to the file path if needed
		$filepath = preg_replace("/(.+?)\/*$/", "\\1/",  $filepath);

		$list = $this->list_files($filepath);

		if ($list !== false and count($list) > 0)
		{
			foreach ($list as $item)
			{
				// If we can't delete the item it's probaly a folder so
				// we'll recursively call delete_dir()
				if ( ! @ftp_delete($this->_conn_id, $item))
				{
					// don't recurse into current of parent directory
					if ( ! preg_match('/\/\.\.|\/\.$/', $item))
					{
						$this->delete_dir($item);
					}
				}
			}
		}

		$result = @ftp_rmdir($this->_conn_id, $filepath);

		if ($result === false)
		{
			if ($this->_debug == true)
			{
				throw new \FtpFileAccessException('Unable to delete');
			}
			return false;
		}

		return true;
	}

	// --------------------------------------------------------------------

	/**
	 * Set file permissions
	 *
	 * @param	string 	$path			the file path
	 * @param	string	$permissions	the permissions
	 * @return	bool
	 * @throws	\FtpFileAccessException
	 */
	public function chmod($path, $permissions)
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		// Permissions can only be set when running PHP 5
		if ( ! function_exists('ftp_chmod'))
		{
			if ($this->_debug == true)
			{
				throw new \FtpFileAccessException('CHMOD function does not exist');
			}
			return false;
		}

		$result = @ftp_chmod($this->_conn_id, $permissions, $path);

		if ($result === false)
		{
			if ($this->_debug == true)
			{
				throw new \FtpFileAccessException('Unable to CHMOD');
			}
			return false;
		}

		return true;
	}

	// --------------------------------------------------------------------

	/**
	 * FTP List files in the specified directory
	 *
	 * @param	string	$path
	 * @return	array
	 */
	public function list_files($path = '.')
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		return ftp_nlist($this->_conn_id, $path);
	}

	// ------------------------------------------------------------------------

	/**
	 * Read a directory and recreate it remotely
	 *
	 * This function recursively reads a folder and everything it contains (including
	 * sub-folders) and creates a mirror via FTP based on it.  Whatever the directory structure
	 * of the original file path will be recreated on the server.
	 *
	 * @param	string	$local_path		path to source with trailing slash
	 * @param	string	$remote_path	path to destination - include the base folder with trailing slash
	 * @return	bool
	 */
	public function mirror($local_path, $remote_path)
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		// Open the local file path
		if ($fp = @opendir($local_path))
		{
			// Attempt to open the remote file path.
			if ( ! $this->change_dir($remote_path, true))
			{
				// If it doesn't exist we'll attempt to create the directory
				if ( ! $this->mkdir($remote_path) or ! $this->change_dir($remote_path))
				{
					return false;
				}
			}

			// Recursively read the local directory
			while (false !== ($file = readdir($fp)))
			{
				if (@is_dir($local_path.$file) and substr($file, 0, 1) != '.')
				{
					$this->mirror($local_path.$file."/", $remote_path.$file."/");
				}
				elseif (substr($file, 0, 1) != ".")
				{
					// Get the file extension so we can se the upload type
					$ext = pathinfo($file, PATHINFO_EXTENSION);
					$mode = $this->_settype($ext);

					$this->upload($local_path.$file, $remote_path.$file, $mode);
				}
			}
			return true;
		}

		return false;
	}

	// --------------------------------------------------------------------

	/**
	 * Set the upload type
	 *
	 * @param	string	$ext
	 * @return	string
	 */
	protected function _settype($ext)
	{
		$text_types = array(
			'txt',
			'text',
			'php',
			'phps',
			'php4',
			'js',
			'css',
			'htm',
			'html',
			'phtml',
			'shtml',
			'log',
			'xml',
		);

		return in_array($ext, $text_types) ? 'ascii' : 'binary';
	}

	// ------------------------------------------------------------------------

	/**
	 * Close the connection
	 *
	 * @return	void
	 */
	public function close()
	{
		if ( ! $this->_is_conn())
		{
			return false;
		}

		@ftp_close($this->_conn_id);
	}

	// ------------------------------------------------------------------------

	/**
	 * Close the connection when the class is unset
	 *
	 * @return	void
	 */
	public function  __destruct()
	{
		$this->close();
	}

}
