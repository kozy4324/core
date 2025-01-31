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

/**
 * The Finder class allows for searching through a search path for a given
 * file, as well as loading a given file.
 *
 * @package     Fuel
 * @subpackage  Core
 */
class Finder
{
	/**
	 * @var  Finder  $instance  Singleton master instance
	 */
	protected static $instance = null;

	public static function _init()
	{
		\Config::load('file', true);

		// make sure the configured chmod values are octal
		$chmod = \Config::get('file.chmod.folders', 0777);
		is_string($chmod) and \Config::set('file.chmod.folders', octdec($chmod));
		$chmod = \Config::get('file.chmod.files', 0666);
		is_string($chmod) and \Config::set('file.chmod.files', octdec($chmod));
	}
	/**
	 * An alias for Finder::instance()->locate();
	 *
	 * @param   string  $dir       Directory to look in
	 * @param   string  $file      File to find
	 * @param   string  $ext       File extension
	 * @param   bool    $multiple  Whether to find multiple files
	 * @param   bool    $cache     Whether to cache this path or not
	 * @return  mixed  Path, or paths, or false
	 */
	public static function search($dir, $file, $ext = '.php', $multiple = false, $cache = true)
	{
		return static::instance()->locate($dir, $file, $ext, $multiple, $cache);
	}

	/**
	 * Gets a singleton instance of Finder
	 *
	 * @return  Finder
	 */
	public static function instance()
	{
		if ( ! static::$instance)
		{
			static::$instance = static::forge(array(APPPATH, COREPATH));
		}

		return static::$instance;
	}

	/**
	 * Forges new Finders.
	 *
	 * @param   array  $paths  The paths to initialize with
	 * @return  Finder
	 */
	public static function forge($paths = array())
	{
		return new static($paths);
	}

	/**
	 * @var  array  $paths  Holds all of the search paths
	 */
	protected $paths = array();

	/**
	 * @var  array  $flash_paths  Search paths that only last for one lookup
	 */
	protected $flash_paths = array();

	/**
	 * @var  int  $cache_lifetime the amount of time to cache in seconds
	 */
	protected $cache_lifetime = null;

	/**
	 * @var  string  $cache_dir path to the cache file location
	 */
	protected $cache_dir = null;

	/**
	 * @var  array  $cached_paths  Cached lookup paths
	 */
	protected $cached_paths = array();

	/**
	 * @var  bool  $cache_valid  Whether the path cache is valid or not
	 */
	protected $cache_valid = true;

	/**
	 * Takes in an array of paths, preps them and gets the party started.
	 *
	 * @param  array  $paths  The paths to initialize with
	 */
	public function __construct($paths = array())
	{
		$this->add_path($paths);
	}

	/**
	 * Adds a path (or paths) to the search path at a given position.
	 *
	 * Possible positions:
	 *   (null):  Append to the end of the search path
	 *   (-1):    Prepend to the start of the search path
	 *   (index): The path will get inserted AFTER the given index
	 *
	 * @param   string|array  $paths  The path to add
	 * @param   int           $pos    The position to add the path
	 * @return  $this
	 * @throws  \OutOfBoundsException
	 */
	public function add_path($paths, $pos = null)
	{
		if ( ! is_array($paths))
		{
			$paths = array($paths);
		}

		foreach ($paths as $path)
		{
			if ($pos === null)
			{
				$this->paths[] = $this->prep_path($path);
			}
			elseif ($pos === -1)
			{
				array_unshift($this->paths, $this->prep_path($path));
			}
			else
			{
				if ($pos > count($this->paths))
				{
					throw new \OutOfBoundsException(sprintf('Position "%s" is out of range.', $pos));
				}
				array_splice($this->paths, $pos, 0, $this->prep_path($path));
			}
		}

		return $this;
	}

	/**
	 * Removes a path from the search path.
	 *
	 * @param   string  $path  Path to remove
	 * @return  $this
	 */
	public function remove_path($path)
	{
		foreach ($this->paths as $i => $p)
		{
			if ($p === $path)
			{
				unset($this->paths[$i]);
				break;
			}
		}

		return $this;
	}

	/**
	 * Adds multiple flash paths.
	 *
	 * @param   array  $paths  The paths to add
	 * @return  $this
	 */
	public function flash($paths)
	{
		if ( ! is_array($paths))
		{
			$paths = array($paths);
		}

		foreach ($paths as $path)
		{
			$this->flash_paths[] = $this->prep_path($path);
		}

		return $this;
	}

	/**
	 * Clears the flash paths.
	 *
	 * @return  $this
	 */
	public function clear_flash()
	{
		$this->flash_paths = array();

		return $this;
	}

	/**
	 * Returns the current search paths...including flash paths.
	 *
	 * @return  array  Search paths
	 */
	public function paths()
	{
		return array_merge($this->flash_paths, $this->paths);
	}

	/**
	 * Prepares a path for usage.  It ensures that the path has a trailing
	 * Directory Separator.
	 *
	 * @param   string  $path  The path to prepare
	 * @return  string
	 */
	public function prep_path($path)
	{
		$path = str_replace(array('/', '\\'), DS, $path);
		return rtrim($path, DS).DS;
	}

	/**
	 * Prepares an array of paths.
	 *
	 * @param   array  $paths  The paths to prepare
	 * @return  array
	 */
	public function prep_paths(array $paths)
	{
		foreach ($paths as &$path)
		{
			$path = $this->prep_path($path);
		}
		return $paths;
	}

	/**
	 * Gets a list of all the files in a given directory inside all of the
	 * loaded search paths (e.g. the cascading file system).  This is useful
	 * for things like finding all the config files in all the search paths.
	 *
	 * @param   string  $directory  The directory to look in
	 * @param   string  $filter     The file filter
	 * @return  array   the array of files
	 */
	public function list_files($directory = null, $filter = '*.php')
	{
		$paths = $this->paths;

		// get extra information of the active request
		if (class_exists('Request', false) and ($uri = \Uri::string()) !== null)
		{
			$paths = array_merge(\Request::active()->get_paths(), $paths);
		}

		// Merge in the flash paths then reset the flash paths
		$paths = array_merge($this->flash_paths, $paths);
		$this->clear_flash();

		$found = array();
		foreach ($paths as $path)
		{
			foreach(new \GlobIterator(rtrim($path.$directory, DS).DS.$filter) as $file)
			{
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}

	/**
	 * Locates a given file in the search paths.
	 *
	 * @param   string  $dir       Directory to look in
	 * @param   string  $file      File to find
	 * @param   string  $ext       File extension
	 * @param   bool    $multiple  Whether to find multiple files
	 * @param   bool    $cache     Whether to cache this path or not
	 * @return  mixed  Path, or paths, or false
	 */
	public function locate($dir, $file, $ext = '.php', $multiple = false, $cache = true)
	{
		$found = $multiple ? array() : false;

		// absolute path requested?
		if ($file[0] === '/' or substr($file, 1, 2) === ':\\')
		{
			// if the base file does not exist, stick the extension to the back of it
			if ( ! is_file($file))
			{
				$file .= $ext;
			}
			if ( ! is_file($file))
			{
				// at this point, found would be either empty array or false
				return $found;
			}
			return $multiple ? array($file) : $file;
		}

		// determine the cache prefix
		if ($multiple)
		{
			// make sure cache is not used if the loaded package and module list is changed
			$cachekey = '';
			class_exists('Module', false) and $cachekey .= implode('|', \Module::loaded());
			$cachekey .= '|';
			class_exists('Package', false) and $cachekey .= implode('|', \Package::loaded());
			$cache_id = md5($cachekey).'.';
		}
		else
		{
			$cache_id = 'S.';
		}

		$paths = array();

		// If a filename contains a :: then it is trying to be found in a namespace.
		// This is sometimes used to load a view from a non-loaded module.
		$pos = strripos($file, '::');

		// regular name
		if ($pos === false)
		{
			$paths = $this->paths;

			// get extra information of the active request
			if (class_exists('Request', false) and ($request = \Request::active()))
			{
				$request->module and $cache_id .= $request->module;
				$paths = array_merge($request->get_paths(), $paths);
			}
		}

		// :: without a namespace, load from the app namespace only
		elseif ($pos === 0)
		{
			$paths = $this->paths;

			$file = substr($file, 2);
		}

		// namespaced file
		else
		{
			// get the namespace path
			if ($path = \Autoloader::namespace_path('\\'.ucfirst(substr($file, 0, $pos))))
			{
				$cache_id .= substr($file, 0, $pos);

				// and strip the classes directory as we need the module root
				$paths = array(substr($path, 0, -8));

				// strip the namespace from the filename
				$file = substr($file, $pos + 2);
			}
			else
			{
				$file = substr($file, 2);
			}
		}

		// Merge in the flash paths then reset the flash paths
		$paths = array_merge($this->flash_paths, $paths);
		$this->clear_flash();

		$file = $this->prep_path($dir).$file.$ext;
		$cache_id .= $file;

		if ($cache and $cached_path = $this->from_cache($cache_id))
		{
			return $cached_path;
		}

		foreach ($paths as $dir)
		{
			$file_path = $dir.$file;

			if (is_file($file_path))
			{
				if ( ! $multiple)
				{
					$found = $file_path;
					break;
				}

				$found[] = $file_path;
			}
		}

		if ( ! empty($found) and $cache)
		{
			$this->add_to_cache($cache_id, $found);
		}

		return $found;
	}

	/**
	 * Reads in the cached paths with the given cache id.
	 *
	 * @param   string  $cache_id  Cache id to read
	 * @return  void
	 */
	public function read_cache($cache_id)
	{
		// make sure we have all config data
		empty($this->cache_dir) and $this->cache_dir = \Config::get('cache_dir', APPPATH.'cache/');
		empty($this->cache_lifetime) and $this->cache_lifetime = \Config::get('cache_lifetime', 3600);

		if ($cached = $this->cache($cache_id))
		{
			$this->cached_paths = $cached;
		}
	}

	/**
	 * Writes out the cached paths if they need to be.
	 *
	 * @param   string  $cache_id  Cache id to read
	 * @return  void
	 */
	public function write_cache($cache_id)
	{
		$this->cache_valid or $this->cache($cache_id, $this->cached_paths);
	}

	/**
	 * Loads in the given cache_id from the cache if it exists.
	 *
	 * @param   string  $cache_id  Cache id to load
	 * @return  string|bool  Path or false if not found
	 */
	protected function from_cache($cache_id)
	{
		$cache_id = md5($cache_id);
		if (array_key_exists($cache_id, $this->cached_paths))
		{
			return $this->cached_paths[$cache_id];
		}

		return false;
	}

	/**
	 * Loads in the given cache_id from the cache if it exists.
	 *
	 * @param   string  $cache_id  Cache id to load
	 * @return  string|bool  Path or false if not found
	 */
	protected function add_to_cache($cache_id, $path)
	{
		$cache_id = md5($cache_id);
		$this->cached_paths[$cache_id] = $path;
		$this->cache_valid = false;
	}

	/**
	 * This method does basic filesystem caching.  It is used for things like path caching.
	 *
	 * This method is from KohanaPHP's Kohana class.
	 *
	 * @param  string  $name      the cache name
	 * @param  array   $data      the data to cache (if non given it returns)
	 * @param  int     $lifetime  the number of seconds for the cache too live
	 * @return bool|null
	 */
	protected function cache($name, $data = null, $lifetime = null)
	{
		// Cache file is a hash of the name
		$file = $name.'.pathcache';

		// Cache directories are split by keys to prevent filesystem overload
		$dir = rtrim($this->cache_dir, DS).DS;

		if ($lifetime === NULL)
		{
			// Use the default lifetime
			$lifetime = $this->cache_lifetime;
		}

		if ($data === null)
		{
			if (is_file($dir.$file))
			{
				if ((time() - filemtime($dir.$file)) < $lifetime)
				{
					// Return the cache
					try
					{
						return unserialize(file_get_contents($dir.$file));
					}
					catch (\Exception $e)
					{
						// Cache exists but could not be read, ignore it
					}
				}
				else
				{
					try
					{
						// Cache has expired
						clearstatcache(true, $dir.$file);
						is_file($dir.$file) and unlink($dir.$file);
					}
					catch (\Exception $e)
					{
						// Cache has mostly likely already been deleted,
						// let return happen normally.
					}
				}
			}

			// Cache not found
			return null;
		}

		if ( ! is_dir($dir))
		{
			// Create the cache directory
			mkdir($dir, \Config::get('file.chmod.folders', 0777), true);

			// Set permissions (must be manually set to fix umask issues)
			try
			{
				chmod($dir, \Config::get('file.chmod.folders', 0777));
			}
			catch (\PhpErrorException $e)
			{
				// if we get something else then a chmod error, bail out
				if (substr($e->getMessage(), 0, 8) !== 'chmod():')
				{
					throw new $e;
				}
			}
		}

		// Force the data to be a string
		$data = serialize($data);

		try
		{
			// Write the cache, and set permissions
			if ($result = (bool) file_put_contents($dir.$file, $data, LOCK_EX))
			{
				try
				{
					chmod($dir.$file, \Config::get('file.chmod.files', 0666));
				}
				catch (\PhpErrorException $e)
				{
					// if we get something else then a chmod error, bail out
					if (substr($e->getMessage(), 0, 8) !== 'chmod():')
					{
						throw new $e;
					}
				}
			}

			return $result;
		}
		catch (\Exception $e)
		{
			// Failed to write cache
			return false;
		}
	}

}
