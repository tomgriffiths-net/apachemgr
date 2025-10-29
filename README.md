# apachemgr
apachemgr is a PHP-CLI package that manages apache servers.

# Functions
- **newServer(string $documentRoot, string $name, string|false $serverRoot=false):int|false**: Downloads and creates a new apache server, if serverRoot is false then it will be installed to the apache folder, returns the server id on success or false on failure.
- **deleteServer(int $serverNumber, bool $deleteRoot=false):bool**: Deletes a specific server, if deleteRoot is true it will actually remove the files in the serverRoot directory, returns true on success or false on failure.
- **start(int $serverNumber):bool**: Starts an apache server as a child process to the current PHP-CLI session, returns true on success or false on failure.
- **getServerProc(int $serverNumber):mixed**: Gets the process resource for an apache server, returns the resource on success or false on failure.
- **stop(int $serverNumber):int|false**: Stops an apache server, returns the exit code on success or false on failure.
- **isRunning(int $serverNumber, bool $deleteProcIfNotRunning=true):bool**: Returns true if an apache server is running in the current PHP-CLI session, returns false otherwise.
- **setServerListen(string|int $identifier, string $listen):bool**: Sets a servers listen directive, can be a port number or ip and port, identifier can be a path to a .conf file or a server number, returns true on success or false on failure.
- **setServerRoot(string|int $identifier, string $serverRoot):bool**: Sets the SRVROOT directive, this is only useful if moving a server manually, thi does not change the root setting in apachemgr, returns true on success or false on failure.
- **setServerDocRoot(string|int $identifier, string $docDir):bool**: Sets the document root of an apache server, returns true on success or false on failure.
- **setServerPhpRoot(string|int $identifier, string $phpDir):bool**: Sets where apache should look for a php installation, returns true on success or false on failure.
- **setConfDirective(string|int $identifier, string $directive, string $value):bool**: Sets a .conf file directive, returns true on success or false on failure.
- **public static function readConf(string|int $identifier):array|false**: Reads a conf file and attempts to format it into a keyed array, returns the array on success or false on failure.
- **getServerRoot(int $serverNumber):string|false**: Returns the a servers installation directory on success or false on failure.
- **getServerPort(int $serverNumber):int|false**: Returns the port a server is listening on on success or false on failure.
- **listServers():array|false**: Returns all the apache servers with their settings  on success or false on failure.