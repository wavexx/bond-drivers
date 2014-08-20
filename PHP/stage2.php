//<?php
// bond PHP interface setup

// Redirect normal output
$__BOND_BUFFERS = array(
    "STDOUT" => "",
    "STDERR" => ""
);

class __BOND_BUFFERED
{
  public $name;

  public function stream_open($path, $mode, $options, &$opened_path)
  {
    global $__BOND_BUFFERS;
    $path = strtoupper(substr(strstr($path, "://"), 3));
    if(!isset($__BOND_BUFFERS[$path]))
      return false;
    $this->name = $path;
    return true;
  }

  public function stream_write($data)
  {
    global $__BOND_BUFFERS;
    $buffer = &$__BOND_BUFFERS[$this->name];
    $buffer .= $data;
    return strlen($data);
  }
}


// Redefine standard streams
$__BOND_CHANNELS = array(
    "STDIN" => $__BOND_STDIN,
    "STDOUT" => fopen("php://stdout", "w"),
    "STDERR" => fopen("php://stderr", "w")
);

stream_wrapper_unregister("php");
stream_wrapper_register("php", "__BOND_BUFFERED");

if(!defined("STDIN"))
  define('STDIN', null);
if(!defined("STDOUT"))
  define('STDOUT', fopen("php://stdout", "w"));
if(!defined("STDERR"))
  define('STDERR', fopen("php://stderr", "w"));


// Define our own i/o methods
function __BOND_output($buffer, $phase)
{
  fwrite(STDOUT, $buffer);
}

function __BOND_getline()
{
  global $__BOND_CHANNELS;
  return rtrim(fgets($__BOND_CHANNELS['STDIN']));
}

function __BOND_sendline($line = '')
{
  global $__BOND_CHANNELS;
  $stdout = $__BOND_CHANNELS['STDOUT'];
  fwrite($stdout, $line . "\n");
  fflush($stdout);
}


// some utilities to get/reset the error state
$__BOND_ERROR_LEVEL = null;

function __BOND_error_type($type)
{
  switch($type)
  {
  case E_ERROR: return 'E_ERROR';
  case E_WARNING: return 'E_WARNING';
  case E_PARSE: return 'E_PARSE';
  case E_NOTICE: return 'E_NOTICE';
  case E_CORE_ERROR: return 'E_CORE_ERROR';
  case E_CORE_WARNING: return 'E_CORE_WARNING';
  case E_CORE_ERROR: return 'E_COMPILE_ERROR';
  case E_CORE_WARNING: return 'E_COMPILE_WARNING';
  case E_USER_ERROR: return 'E_USER_ERROR';
  case E_USER_WARNING: return 'E_USER_WARNING';
  case E_USER_NOTICE: return 'E_USER_NOTICE';
  case E_STRICT: return 'E_STRICT';
  case E_RECOVERABLE_ERROR: return 'E_RECOVERABLE_ERROR';
  case E_DEPRECATED: return 'E_DEPRECATED';
  case E_USER_DEPRECATED: return 'E_USER_DEPRECATED';
  }
  return $type;
}

function __BOND_clear_error()
{
  // cheap way to reset the last error state
  @trigger_error(null);
}

function __BOND_get_error()
{
  $err = error_get_last();
  if(!empty($err['message']))
  {
    if(ini_get('display_errors') && ($err['type'] & error_reporting()))
    {
      $type = __BOND_error_type($err['type']);
      fwrite(STDERR, "PHP[$type]: " . $err['message'] . "\n");
    }
    if($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR))
      return $err;
  }
  return false;
}


// Serialization methods
class _BOND_SerializationException extends Exception {}

function __BOND_dumps($data)
{
  __BOND_clear_error();
  $code = @json_encode($data);
  $err =  error_get_last();
  if(json_last_error() || !empty($err['message']))
    throw new _BOND_SerializationException(@"cannot encode $data");
  return $code;
}

function __BOND_loads($string)
{
  return json_decode($string);
}


// Recursive repl
$__BOND_TRANS_EXCEPT = null;

function __BOND_call($name, $args)
{
  $code = __BOND_dumps(array($name, $args));
  __BOND_sendline("CALL $code");
  return __BOND_repl();
}

function __BOND_eval($code)
{
  // encase "code" in an anonymous block, hiding our local variables and
  // simulating the global scope
  $SENTINEL = 1;
  __BOND_clear_error();
  $ret = @eval("return call_user_func(function()
  {
    extract(\$GLOBALS);
    return ($code);
  }, null);");
  $err = __BOND_get_error();
  if($err) throw new Exception($err['message']);
  return $ret;
}

function __BOND_exec($code)
{
  // like "eval", but exports any local definition to the global scope
  $SENTINEL = 1;
  __BOND_clear_error();
  @eval("call_user_func(function()
  {
    extract(\$GLOBALS, EXTR_REFS);
    { $code }
    \$__BOND_ERROR_LEVEL = error_reporting();
    \$__BOND_VARS = get_defined_vars();
    foreach(\$__BOND_VARS as \$k => &\$v)
      if(!isset(\$GLOBALS[\$k]))
	\$GLOBALS[\$k] = \$v;
    foreach(array_keys(\$GLOBALS) as \$k)
      if(!isset(\$__BOND_VARS[\$k]))
	unset(\$GLOBALS[\$k]);
  }, null);");
  $err = __BOND_get_error();
  if($err) throw new Exception($err['message']);
}

function __BOND_repl()
{
  global $__BOND_BUFFERS, $__BOND_TRANS_EXCEPT;
  while($line = __BOND_getline())
  {
    $line = explode(" ", $line, 2);
    $cmd = $line[0];
    $args = (count($line) > 1? __BOND_loads($line[1]): array());

    $ret = null;
    $err = null;
    switch($cmd)
    {
    case "EVAL":
      try { $ret = __BOND_eval($args); }
      catch(Exception $e) { $err = $e; }
      break;

    case "EVAL_BLOCK":
      try { __BOND_exec($args); }
      catch(Exception $e) { $err = $e; }
      break;

    case "EXPORT":
      $name = $args;
      if(function_exists($name))
	$err = "Function \"$name\" already exists";
      else
      {
	$code = "function $name() { return __BOND_call('$args', func_get_args()); }";
	__BOND_clear_error();
	@eval($code);
	$err = __BOND_get_error();
      }
      break;

    case "CALL":
      try
      {
	$name = $args[0];
	if(is_callable($name))
	{
	  // special-case regular functions for performance
	  __BOND_clear_error();
	  $ret = @call_user_func_array($args[0], $args[1]);
	  $err = __BOND_get_error();
	}
	elseif(preg_match("/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/", $name))
	{
	  // avoid fatal errors, but still emit proper exceptions as opposed to "warnings"
	  throw new Exception("undefined function \"$name\"");
	}
	else
	{
	  // construct a string that we can interpret "function-like", to
	  // handle also function references and method calls uniformly
	  $args_ = array();
	  foreach($args[1] as $el)
	    $args_[] = var_export($el, true);
	  $args_ = implode(", ", $args_);
	  $ret = __BOND_eval("$name($args_)");
	}
      }
      catch(Exception $e)
      {
	$err = $e;
      }
      break;

    case "RETURN":
      return $args;

    case "EXCEPT":
      throw new Exception($args);

    case "ERROR":
      throw new _BOND_SerializationException($args);

    default:
      exit(1);
    }

    // redirected channels
    ob_flush();
    foreach($__BOND_BUFFERS as $chan => &$buf)
    {
      if(strlen($buf))
      {
	$code = __BOND_dumps(array($chan, $buf));
	__BOND_sendline("OUTPUT $code");
	$buf = "";
      }
    }

    // error state
    $state = "RETURN";
    if($err)
    {
      if($err instanceOf _BOND_SerializationException)
      {
	$state = "ERROR";
	$ret = $err->getMessage();
      }
      else
      {
	$state = "EXCEPT";
	if($err instanceOf Exception)
	  $ret = ($__BOND_TRANS_EXCEPT? $err: $err->getMessage());
	else
	  $ret = @"$err";
      }
    }
    $code = null;
    try
    {
      $code = __BOND_dumps($ret);
    }
    catch(Exception $e)
    {
      $state = "ERROR";
      $code = __BOND_dumps($e->getMessage());
    }
    __BOND_sendline("$state $code");
  }
  return 0;
}

function __BOND_start($proto, $trans_except)
{
  global $__BOND_TRANS_EXCEPT, $__BOND_ERROR_LEVEL;
  ob_start('__BOND_output');
  $__BOND_ERROR_LEVEL = error_reporting();
  $__BOND_TRANS_EXCEPT = (bool)($trans_except);
  __BOND_sendline("READY");
  $ret = __BOND_repl();
  __BOND_sendline("BYE");
  exit($ret);
}
