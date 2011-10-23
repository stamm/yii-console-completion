LCompleteCommand adds bash complete functionality to Yii console application.

To attach put the class file somewhere (for example in extensions application subdirectory) and add command definition to config file, for instance:

    'commandMap' => array(
    	'complete' => array(
    		'class' => 'ext.LCompleteCommand',
    		//'bashFile' => '/etc/bash_completion.d/yii_applications' //Defaults to </etc/bash_completion.d/yii_applications>. May be changed if needed
    	),
    ),