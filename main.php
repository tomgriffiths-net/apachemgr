<?php
class apachemgr{
    private static $procs = [];//

    public static function newServer(string $documentRoot, string $name, string|false $serverRoot=false):int|false{
        if(!is_dir($documentRoot)){
            if(!mkdir($documentRoot, 0777, true)){
                mklog(2,'Failed to create document directory');
                return false;
            }
        }
        
        $serverNumber = 1;
        while(settings::isset("servers/" . $serverNumber)){
            $serverNumber ++;
        }

        if(is_string($serverRoot)){
            if(is_dir($serverRoot) || is_file($serverRoot)){
                mklog(2,'Server root path ' . $serverRoot . ' already exists');
                return false;
            }
        }
        else{
            $serverRoot = getcwd() . "\\apache\\" . $serverNumber;
            mklog(1, 'Installing to default location ' . $serverRoot);
        }

        if(!self::downloadApache($serverRoot)){
            mklog(2,'Failed to download and install apache to ' . $serverRoot);
            return false;
        }

        if(!self::setServerDocRoot($serverRoot . '\\conf\\httpd.conf', realpath($documentRoot))){
            mklog(2, 'Failed to set document root');
            return false;
        }

        if(!self::setServerListen($serverRoot . '\\conf\\httpd.conf', (string) (80 + $serverNumber))){
            mklog(2, 'Failed to set server port');
            return false;
        }

        if(!settings::set('servers/' . $serverNumber, ['name'=>$name, 'root'=>$serverRoot])){
            mklog(2, 'Failed to save server info');
        }

        return $serverNumber;
    }

    public static function start(int $serverNumber):bool{
        $info = settings::read('servers/' . $serverNumber);
        if(!is_array($info) || !isset($info['root'])){
            mklog(2, 'Failed to get info for server ' . $serverNumber);
            return false;
        }

        $port = self::getServerPort($serverNumber);
        if(is_int($port)){
            if(network::ping('127.0.0.1', $port)){
                mklog(2, 'Cannot start server ' . $serverNumber . ' as port ' . $port . ' is already in use');
                return false;
            }
        }
        else{
            mklog(1, 'Unable to get server ' . $serverNumber . ' port, unable to check port availability');
        }

        if(!is_file($info['root'] . '\\bin\\httpd.exe')){
            mklog(2, 'Failed to find httpd.exe for server ' . $serverNumber);
            return false;
        }

        if(isset(self::$procs[$serverNumber])){
            mklog(2, 'Apache server ' . $serverNumber . ' already has an open process');
            return false;
        }

        self::$procs[$serverNumber] = proc_open(realpath($info['root'] . '\\bin\\httpd.exe'), [], $pipes, realpath($info['root']), null, ['bypass_shell'=>true]);

        if(!is_resource(self::$procs[$serverNumber])){
            mklog(2, 'Failed to start httpd.exe process');
            return false;
        }

        $status = proc_get_status(self::$procs[$serverNumber]);

        if(!$status['running']){
            mklog(2, 'Apache has unexpextedly exited with code ' . $status['exitcode']);
            return false;
        }

        return true;
    }
    public static function getServerProc(int $serverNumber):mixed{
        return isset(self::$procs[$serverNumber]) ? self::$procs[$serverNumber] : false;
    }
    public static function stop(int $serverNumber):int|false{
        if(!isset(self::$procs[$serverNumber])){
            mklog(2, 'Server number ' . $serverNumber . ' is not running or was not started by this php session');
            return false;
        }

        $pipe = '\\\\.\\pipe\\apachemgr_server_' . $serverNumber;

        $fp = @fopen($pipe, 'wb');
        if($fp === false){
            mklog(2, 'Failed to connect to named pipe for ' . $serverNumber);
            return false;
        }
        if(!fwrite($fp, "stop\n")){
            mklog(2, 'Failed to write to named pipe for ' . $serverNumber);
            return false;
        }
        @fclose($fp);
        
        mklog(1, 'Waiting for apache to shut down gracefully');
        $tries = 0;
        while(true){
            if($tries > 10){
                mklog(2, 'Killing apache server ' . $serverNumber . ' as it has not shut down after 10 seconds');
                if(!proc_terminate(self::$procs[$serverNumber])){
                    mklog(2, 'Failed to send terminate signal to apache server');
                    return false;
                }
            }

            if(!proc_get_status(self::$procs[$serverNumber])['running']){
                break;
            }

            sleep(1);
        }

        $exit = proc_close(self::$procs[$serverNumber]);

        unset(self::$procs[$serverNumber]);

        return $exit;
    }

    public static function setServerListen(string|int $identifier, string $listen):bool{
        if(!preg_match('/^[0-9.:]+$/', $listen)){
            return false;
        }
        
        return self::setConfDirective($identifier, "Listen", $listen);
    }
    public static function setServerRoot(string|int $identifier, string $serverRoot):bool{
        $serverRoot = str_replace("\\", "/", $serverRoot);
        $serverRoot = rtrim($serverRoot, "/");

        return self::setConfDirective($identifier, "Define SRVROOT", "\"" . $serverRoot . "\"");
    }
    public static function setServerDocRoot(string|int $identifier, string $docDir):bool{
        $docDir = str_replace("\\", "/", $docDir);
        $docDir = rtrim($docDir, "/");
        files::ensureFolder($docDir);

        return self::setConfDirective($identifier, "Define DOCROOT", "\"" . $docDir . "\"");
    }
    public static function setServerPhpRoot(string|int $identifier, string $phpDir):bool{
        $phpDir = str_replace("\\", "/", $phpDir);
        $phpDir = rtrim($phpDir, "/");

        return self::setConfDirective($identifier, "Define PHPROOT", "\"" . $phpDir . "\"");
    }
    public static function setConfDirective(string|int $identifier, string $directive, string $value):bool{
        if(is_int($identifier)){
            $identifier = self::getServerRoot($identifier);
            if(!is_string($identifier)){
                return false;
            }
            $identifier .= "\\conf\\httpd.conf";
        }

        $lines = file($identifier);
        if(!is_array($lines)){
            mklog(2,'Failed to read config file ' . $identifier);
            return false;
        }

        $lines = self::replaceLineBeginingWith($directive, $directive . " " . $value, $lines);

        if(!is_array($lines)){
            mklog(2,'Failed to set ' . $directive . ' directive in ' . $identifier);
            return false;
        }

        if(!file_put_contents($identifier, $lines)){
            mklog(2,'Failed to save config file ' . $identifier);
            return false;
        }

        return true;
    }
    public static function setAutostart(int $serverNumber, bool $autostart):bool{
        if(!settings::isset('servers/' . $serverNumber)){
            mklog(2,'Failed to find server ' . $serverNumber);
            return false;
        }

        return settings::set('servers/' . $serverNumber . '/autostart', $autostart, true);
    }
    public static function deleteServer(int $serverNumber, bool $deleteRoot=false):bool{
        $info = settings::read('servers/' . $serverNumber);
        if(!is_array($info) || !isset($info['root'])){
            mklog(2, 'Unable to find server number ' . $serverNumber);
            return false;
        }

        if(!settings::unset('servers/' . $serverNumber)){
            mklog(2, 'Unable to delete information for server ' . $serverNumber);
            return false;
        }

        if($deleteRoot){
            exec('rmdir /s /q "' . str_replace("/", "\\", $info['root']) . '"', $output, $exitCode);

            if($exitCode !== 0){
                return false;
            }
        }
        
        return true;
    }

    public static function readConf(string|int $identifier):array|false{
        $return = [];

        if(is_int($identifier)){
            $identifier = self::getServerRoot($identifier);
            if(!is_string($identifier)){
                return false;
            }
            $identifier .= "\\conf\\httpd.conf";
        }

        $lines = file($identifier);
        if(!is_array($lines)){
            echo "Failed to open file";
            return false;
        }

        $levelsSuffix = [];
        $lastChar = " ";

        foreach($lines as $line){
            $line = trim($line);
            
            if(substr($line,0,1) === "#" || empty($line)){
                continue;
            }

            if(substr($line,0,2) === "</"){
                array_pop($levelsSuffix);
                continue;
            }
            elseif(substr($line,0,1) === "<"){
                $levelsSuffix[] = substr($line,1,-1);
                continue;
            }

            $levels = [];

            $inQuotes = false;
            $level = 0;
            foreach(str_split($line) as $char){
                if($char === '"' && $lastChar !== "\\"){
                    $inQuotes = !$inQuotes;
                }
                else{
                    if($char === " " && !$inQuotes){
                        if(isset($levels[$level]) && !empty($levels[$level])){
                            if(empty($levelsSuffix) || $level < 1){
                                $level++;
                                continue;
                            }
                        }
                    }
                }

                if(isset($levels[$level])){
                    $levels[$level] .= $char;
                }
                else{
                    $levels[$level] = $char;
                }

                $lastChar = $char;
            }

            $levels = array_merge($levelsSuffix, $levels);

            $lineEval = '$return';
            $lastDepth = count($levels) -1;
            foreach($levels as $levelDepth => $levelValue){
                $levelValue = str_replace("\\","\\\\",str_replace("'","\\'",$levelValue));
                if($levelDepth !== $lastDepth){
                    $lineEval .= '[\'' . $levelValue . '\']';
                }
                else{
                    if(eval('return isset(' . $lineEval . ');')){
                        $temp = eval('return ' . $lineEval . ';');
                        if(gettype($temp) === "array"){
                            $lineEval .= '[] = \'' . $levelValue . '\'';
                        }
                        else{
                            $lineEval .= ' = [\'' . $temp . '\',\'' . $levelValue . '\']';
                        }
                    }
                    else{
                        $lineEval .= ' = \'' . $levelValue . '\'';
                    }
                }
            }

            try{
                eval($lineEval . ';');
            }
            catch(\Error){
                return false;
            }
        }

        return $return;
    }
    public static function getServerRoot(int $serverNumber):string|false{
        $info = settings::read('servers/' . $serverNumber);
        if(!is_array($info) || !isset($info['root'])){
            return false;
        }
        return $info['root'];
    }
    public static function getServerPort(int $serverNumber):int|false{
        $root = self::getServerRoot($serverNumber);
        if(!is_string($root)){
            mklog(2, 'Failed to get server root for server ' . $serverNumber);
            return false;
        }

        $line = self::getLineBeginingWith('Listen',  $root . "\\conf\\httpd.conf");

        if(!is_string($line)){
            mklog(2, 'Failed to find server listen in httpd.conf for server ' . $serverNumber);
            return false;
        }

        $dots = strripos($line, ':');
        if($dots){
            return intval(substr($line, $dots+1));
        }
        else{
            return intval(trim(substr($line, 7)));
        }
    }
    
    private static function replaceLineBeginingWith(string $starting, string $replacement, array $lines):array|false{
        $count = strlen($starting);
        $somethingHappened = false;
        foreach($lines as $index => $line){
            $line = trim($line);
            if(empty($line) || substr($line,0,1) === "#"){
                continue;
            }

            if(substr($line,0,$count) === $starting){
                $lines[$index] = $replacement . "\n";
                $somethingHappened = true;
                break;
            }
        }

        if(!$somethingHappened){
            return false;
        }

        return $lines;
    }
    private static function getLineBeginingWith(string $starting, string $file, bool $ignoreCase=true):string|false{
        $lines = file($file);
        if(!is_array($lines)){
            return false;
        }

        if($ignoreCase){
            $starting = strtolower($starting);
        }

        $startLen = strlen($starting);

        foreach($lines as $line){
            $line = trim($line);

            if($ignoreCase){
                $line = strtolower($line);
            }

            if(substr($line, 0, $startLen) === $starting){
                return $line;
            }
        }

        return false;
    }
    private static function downloadApache(string $serverRoot):bool{
        if(!downloader::downloadFile('https://files.tomgriffiths.net/php-cli/files/httpd-2.4.65-250724-Win64-VS17-CustomConfAndModuleV2.zip','temp/apachemgr/apache.zip')){
            mklog(2,'Failed to download apache with custom config');
            return false;
        }

        $zip = new ZipArchive;
        $result = $zip->open('temp/apachemgr/apache.zip');
        if($result !== true){
            mklog(2,'Failed to open downloaded file');
            return false;
        }

        if(!$zip->extractTo($serverRoot)){
            mklog(2,'Failed to unzip apache');
            return false;
        }
        $zip->close();

        @unlink('temp/apachemgr/apache.zip');

        $serverRoot = realpath($serverRoot);
        if(!is_string($serverRoot)){
            mklog(2,'Failed to get absolute path of server root');
            return false;
        }

        if(!self::setServerRoot($serverRoot . '/conf/httpd.conf', $serverRoot)){
            mklog(2,'Failed to set server root in config file');
            return false;
        }

        if(!self::setServerPhpRoot($serverRoot . '/conf/httpd.conf', getcwd() . '/php')){
            mklog(2,'Failed to set php root in config file');
            return false;
        }

        return true;
    }
}