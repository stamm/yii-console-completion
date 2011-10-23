<?php
/**
 * LCompleteCommand adds bash complete functionality to Yii console application
 * To attach put the class file somewhere (for example in extensions application subdirectory) and add command definition to config file, for instance:
 * 'commandMap' => array(
        ...
        'complete' => array(
            'class' => 'ext.LCompleteCommand',
            //'bashFile' => '/etc/bash_completion.d/yii_applications' //Defaults to </etc/bash_completion.d/yii_applications>. May be changed if needed
        ),
        ...
    ),
 */
class LCompleteCommand extends CConsoleCommand {
    const BASH_COMPLETE_FUNCTION = '_yii_console_application()
{
    local script cur opts
    COMPREPLY=()
    script="${COMP_WORDS[0]}"
    cur="${COMP_WORDS[COMP_CWORD]}"

    SAVE_IFS=$IFS
    IFS=","
    BUFFER="${COMP_WORDS[*]}"
    IFS=$SAVE_IFS

    # Pipe is used to overcome strange bash behavior bug which happens while direct php calls. (Possibly Ubuntu only)
    opts=`echo "$BUFFER" | ${script} complete`

    COMPREPLY=( $(compgen -W "${opts}" -- ${cur}) )
    return 0

}';
    const BASH_REGISTER_COMPLETE_TEMPLATE = "#Program %s\ncomplete -F _yii_console_application %s";
    const PROGRAM_REGEX = '/#Program\s(.*)/';

    /**
     * @var string Path to file to be loaded by bash for enabling completion of Yii applications.
     */
    public $bashFile = '/etc/bash_completion.d/yii_applications';

    /**
     * Generates bash completion suggestions based on user input.
     * @return void
     */
    public function actionIndex() {
        $suggestions = $this->getCommandNames();
        $input = $this->parseInput();

        if($input) {
            $commandName = false;
            $actionName = false;
            $args = array();
            foreach($input as &$item) {
                if($item == '-' || $item == '--') {
                    break;
                }
                elseif(strpos($item, '--') === 0) {
                    $args[] = $item;
                }
                else {
                    if(!$commandName) {
                        $commandName = $item;
                    }
                    elseif(!$actionName) {
                        $actionName = $item;
                    }
                    else {
                        continue;
                    }
                }
            }
            if($commandName) {
                if(isset($this->getCommandRunner()->commands[$commandName])) {
                    $commandObject = $this->getCommandRunner()->createCommand($commandName);
                    $commandActions = $this->getCommandActions($commandObject);
                    if(!$actionName || !in_array($actionName, $commandActions)) {
                        $suggestions = $this->getActionArgsSuggestions($commandObject, $commandObject->defaultAction, $args);
                        if(!$args){
                            $suggestions = array_merge($commandActions, $suggestions);
                        }
                    }
                    else {
                        $suggestions = $this->getActionArgsSuggestions($commandObject, $actionName, $args);
                    }
                }
            }


        }

        // Output the space-separated completion possibilities.
        echo implode(' ', $suggestions);
    }

    /**
     * Installs completion script for current program to {@link LCompleteCommand::$bashFile}
     * @return void
     */
    public function actionInstall() {
        $this->checkWriteAccess();
        $commands = $this->getRegisteredApplications();
        $commands[] = $this->getScriptName();
        $commands = array_unique($commands);
        $this->writeBashFile($commands);
    }

    /**
     * Removes completion script for current program from {@link LCompleteCommand::$bashFile}
     * @return void
     */
    public function actionUninstall(){
        $this->checkWriteAccess();
        $commands = $this->getRegisteredApplications();
        $commands = array_diff($commands, array($this->getScriptName()));
        $commands = array_unique($commands);
        $this->writeBashFile($commands);
    }

    /**
     * Install and Uninstall actions need write permissions on {@link LCompleteCommand::$bashFile}
     * This method performs permission check end exit script on fail
     * @return void
     */
    protected function checkWriteAccess(){
        if((file_exists($this->bashFile) && !is_writable($this->bashFile)) || (!is_writable(dirname($this->bashFile)))) {
            echo "Need to be a root or to have write permissions on <{$this->bashFile}> file", PHP_EOL;
            Yii::app()->end(1);
        }
    }

    /**
     * @return array List of registered commands for current application
     */
    public function getCommandNames() {
        $commandNames = array_keys($this->getCommandRunner()->commands);
        //Adding Yii built in help command to the list
        $commandNames[] = 'help';
        array_unique($commandNames);
        return $commandNames;
    }

    /**
     * @return array List of params user currently entered in terminal
     */
    protected function parseInput() {
        $input = file_get_contents('php://stdin');
        $params = explode(',', $input);
        array_shift($params);
        $newParams = array();

        $i = 0;

        //Here is some logic to handle default bash $COMP_WORDBREAKS containing "=" symbol.
        while($i<count($params)){
            $current = trim($params[$i]);
            $next = isset($params[$i+1]) ? trim($params[$i+1]) : null;
            if($next == '='){
                if(isset($params[$i+2]) && $params[$i+2]{0} != '-'){
                    $newParams[] = $current.$next.trim($params[$i+2]);
                    $i+=3;
                }
                else{
                    $newParams[] = $current.$next;
                    $i += 2;
                }
            }
            else{
                $newParams[] = $current;
                $i++;
            }
        }

        $params = array_filter($newParams);
        return $params;
    }

    /**
     * @param CConsoleCommand $command Command to analyze
     * @return array List of available actions in command
     */
    public function getCommandActions(CConsoleCommand $command) {

        $actions = array();
        $class=new ReflectionClass($command);
        foreach($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
        {
            /** @var $method ReflectionMethod  */
        	$name=$method->getName();
        	if(!strncasecmp($name,'action',6) && strlen($name)>6)
        	{
        		$name=substr($name,6);
        		$name[0]=strtolower($name[0]);

                $actions[] = $name;
        	}
        }

        return $actions;
    }

    /**
     * Retrieves params suggestions for action
     * @param CConsoleCommand $command Command object to process
     * @param string $action Action name to process
     * @param array $args List of already entered arguments. Used to avoid repeatable suggestions
     * @return array List of suggestions based on action params
     */
    public function getActionArgsSuggestions(CConsoleCommand $command, $action, $args = array()) {
        $method = 'action' . $action;

        if(!method_exists($command, $method)){
            return array();
        }
        
        $reflectionAction = new ReflectionMethod($command, $method);
        $suggestions = array();
        foreach($reflectionAction->getParameters() as $parameter) {
            /** @var $parameter ReflectionParameter */
            if($parameter->getName() == 'args') continue;
            $suggestion = '--' . $parameter->getName();
            if(!($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === false)) {
                $suggestion .= '=';
            }
            $alreadySuggested = false;
            if(!$parameter->isArray()){
                foreach($args as $arg){
                    if(strpos($arg, $suggestion) === 0){
                        $alreadySuggested = true;
                        break;
                    }
                }
            }
            if(!$alreadySuggested){
                $suggestions[] = $suggestion;
            }
        }
        return $suggestions;
    }

    /**
     * Writes completion script to {@link LCompleteCommand::$bashFile}
     * @param array $applications List of applications to register for bash completion using _yii_console_application()
     * @return int|bool Result of file_put_contents execution
     */
    protected function writeBashFile($applications){
        echo "Writing config to <{$this->bashFile}>", PHP_EOL;
        if(file_put_contents($this->bashFile, $this->formatCompleteScript($applications))){
            echo 'Success. Changes will be loaded to new bash sessions.', PHP_EOL;
        }
        else{
            echo 'Failed.', PHP_EOL;
            Yii::app()->end(1);
        }
    }

    /**
     * @param array $applications List of applications to register for bash completion using _yii_console_application()
     * @return string Formatted script to be loaded by bash.
     */
    protected function formatCompleteScript($applications) {
        if(!is_array($applications)) {
            $applications = array($applications);
        }
        $parts = array(self::BASH_COMPLETE_FUNCTION);
        foreach($applications as &$application) {
            $parts[] = sprintf(self::BASH_REGISTER_COMPLETE_TEMPLATE, $application, $application);
        }

        return implode(PHP_EOL.PHP_EOL, $parts);
    }

    /**
     * @return array List of applications already registered for bash completion using _yii_console_application() in {@link LCompleteCommand::$bashFile}
     */
    protected function getRegisteredApplications() {
        $return = array();
        if(is_file($this->bashFile)) {
            if(preg_match_all(self::PROGRAM_REGEX, file_get_contents($this->bashFile), $matches)) {
                return $matches[1];
            }
        }
        return $return;
    }

    /**
     * @return string Applications entry script name
     */
    protected function getScriptName(){
        return basename($this->getCommandRunner()->getScriptName());
    }


}