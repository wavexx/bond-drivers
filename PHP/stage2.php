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
$__BOND_last_error = null;

function __BOND_error($errno, $errstr, $errfile, $errline, $errcontext)
{
  global $__BOND_last_error;
  $__BOND_last_error = $errstr;
  if(!(error_reporting() & $errno)) return;
  fwrite(STDERR, $errstr);
}

function __BOND_clear_error()
{
  global $__BOND_last_error;
  $__BOND_last_error = null;
  restore_error_handler();
  @trigger_error(null);
  set_error_handler('__BOND_error');
}

function __BOND_get_error()
{
  // the normal error handler can't trap all errors (notably, parse errors).
  // we need a mixture of both our custom handler and the PHP error state to
  // trap correctly all errors in PHP<5.6
  global $__BOND_last_error;
  if($__BOND_last_error) return $__BOND_last_error;
  $err = error_get_last();
  if($err) $err = $err["message"];
  return $err;
}


// Serialization methods
class _BOND_SerializationException extends Exception {}

function __BOND_dumps($data)
{
  __BOND_clear_error();
  $code = @json_encode($data);
  if(json_last_error() || __BOND_get_error())
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
  if($err) throw new Exception($err);
  return $ret;
}

function __BOND_exec($code)
{
  $SENTINEL = 1;
  $prefix = "__BOND";
  $prefix_len = strlen($prefix);
  $prefix = var_export($prefix, true);

  // like "eval", but exports any local definition to the global scope
  __BOND_clear_error();
  @eval("call_user_func(function()
  {
    extract(\$GLOBALS);
    { $code }
    \$__BOND_vars = get_defined_vars();
    foreach(\$__BOND_vars as \$k => &\$v)
      \$GLOBALS[\$k] = &\$v;
    foreach(array_keys(\$GLOBALS) as \$k)
      if(!isset(\$__BOND_vars[\$k]))
        unset(\$GLOBALS[\$k]);
  }, null);");
  $err = __BOND_get_error();
  if($err) throw new Exception($err);
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
	if(preg_match("/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/", $name) || is_callable($name))
	{
	  // special-case regular functions to avoid fatal errors in PHP
	  __BOND_clear_error();
	  $ret = @call_user_func_array($args[0], $args[1]);
	  $err = __BOND_get_error();
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
  global $__BOND_TRANS_EXCEPT;
  set_error_handler('__BOND_error');
  ob_start('__BOND_output');
  $__BOND_TRANS_EXCEPT = (bool)($trans_except);
  __BOND_sendline("READY");
  $ret = __BOND_repl();
  __BOND_sendline("BYE");
  exit($ret);
}
