<?php
global $BW_SESSION;
/** @noinspection SpellCheckingInspection,RedundantSuppression */
$BW_SESSION=($argv[2] ?? 'FILL_ME_IN');

print RunMe($argv)."\n";

//Make sure “bw” is installed, given session is valid, and is synced
function CheckBWStatus(): ?string
{
	if(is_string($StatusCheck=RunBW('status')))
		return $StatusCheck;
	else if(!is_object($StatusCheck) || !isset($StatusCheck->status))
		return 'bw return does not have status';
	else if($StatusCheck->status==='locked')
		return 'bw is locked';
	else if($StatusCheck->status!=='unlocked')
		return 'bw status is invalid';

	$ExpectedMessage='Syncing complete.';
	if(is_string($SyncCheck=RunBW('sync', null, false)))
		return $SyncCheck;
	else if($SyncCheck[0]!==$ExpectedMessage)
		return "Sync expected “${ExpectedMessage}” but got “${SyncCheck[0]}”";

	return null;
}

//Pull the known folders
function PullKnownFolders(): string|array
{
	$FolderIDs=[];
	if(is_string($FolderList=RunBW('list folders')))
		return $FolderList;
	foreach($FolderList as $Folder)
		$FolderIDs[$Folder->name]=$Folder->id;
	unset($FolderIDs['No Folder']);
	return $FolderIDs;
}

//Get the file handle and the column indexes
function GetFileAndColumns(): string|array
{
	//Prepare the import file for reading
	ini_set('default_charset', 'UTF-8');
	$FileName=$argv[1] ?? './RfExport.csv';
	if(!file_exists($FileName))
		return 'File not found: '.$FileName;
	else if(!($f=fopen($FileName, 'r')))
		return 'Error opening file: '.$FileName;

	//Check the header row values
	if(!($HeadRow=fgetcsv($f)))
		return 'Error opening file: '.$FileName;
	else if(!count($HeadRow) || $HeadRow[0]===NULL)
		return 'Missing head row: '.$FileName;
	if(str_starts_with($HeadRow[0], "\xEF\xBB\xBF")) //Remove UTF8 BOM
		$HeadRow[0]=substr($HeadRow[0], 3);
	unset($FileName);

	$ExpectedCols=array_flip(['Url', 'Name', 'MatchUrl', 'Login', 'Pwd', 'Note', 'Folder', 'RfFieldsV2']);
	$ColNums=[];
	foreach($HeadRow as $Index => $Name) {
		if(!isset($ExpectedCols[$Name]))
			if(isset($ColNums[$Name]))
				return 'Duplicate column title: '.$Name;
			else
				return 'Unknown column title: '.$Name;
		$ColNums[$Name]=$Index;
		unset($ExpectedCols[$Name]);
	}
	if(count($ExpectedCols))
		return 'Required columns not found: '.implode(', ', array_keys($ExpectedCols));
	else if($ColNums['RfFieldsV2']!==count($ColNums)-1)
		return 'RfFieldsV2 must be the last column';

	return [$f, $ColNums];
}

//Process the rows
function ProcessRows($f, array $ColNums, array &$FolderIDs): array
{
	$Counts=['Error'=>0, 'Warning'=>0, 'Success'=>0];
	$RowNum=0;
	$FolderNames=[];
	$Items=[];
	while($Line=fgetcsv($f, null, ",", "\"", "")) {
		//Process the row and add result type to counts
		$RowNum++;
		$Result=ProcessRow($Line, $ColNums);
		$Counts[$Result[0]]++;

		//Handle errors and warnings
		if($Result[0]!=='Success') {
				print "$Result[0]: Row #$RowNum $Result[1]\n";
				continue;
		} else if(isset($Result['Warnings']) && count($Result['Warnings'])) {
			print "Warning(s): Row #$RowNum ".implode("\n", $Result['Warnings'])."\n";
			$Counts['Warning']+=count($Result['Warnings']);
		}

		//Add the folder to the list of folders (strip leading slash)
		$FolderName=($Line[$ColNums['Folder']] ?? '');
		$FolderName=substr($FolderName, ($FolderName[0] ?? '')==='/' ? 1 : 0);
		$Result['Data']->folderId=&$FolderIDs[$FolderName];
		$FolderNames[$FolderName]=1;

		//Save the entry
		$Items[]=$Result['Data'];
	}
	fclose($f);
	return [$Counts, $FolderNames, $Items, $RowNum];
}

//Process a single row
function ProcessRow($Line, $ColNums): array
{
	//Skip blank lines
	if(!count($Line) || (count($Line)===1 && $Line[0]===null))
		return ['Warning', 'is blank'];

	//Extract the columns by name
	$C=(object)[];
	foreach($ColNums as $Name => $ColNum)
		$C->$Name=$Line[$ColNum] ?? '';

	//Check for errors and end processing early for notes
	if($C->Name==='')
		return ['Error', 'is missing a name'];
	else if($C->Url==='' && $C->MatchUrl==='' & $C->Login==='' && $C->Pwd==='') {
		return ['Success', 'Data'=>(object)['type'=>2, 'notes'=>$C->Note, 'name'=>$C->Name, 'secureNote'=>['type'=>0]]];
	}

	//Create a login card
	$Ret=(object)[
		'type'=>1,
		'name'=>$C->Name,
		'notes'=>$C->Note===''  ? null : $C->Note,
		'login'=>(object)[
			'uris'=>[(object)['uri'=>$C->Url]],
			'username'=>$C->Login,
			'password'=>$C->Pwd,
		],
	];
	if($C->MatchUrl!==$C->Url)
		$Ret->login->uris[]=(object)['uri'=>$C->MatchUrl];

	//Create error string for mist fields
	$i=0; //Declared up here so it can be used in $FormatError
	$FormatError=function($Format, ...$Args) use ($ColNums, &$i): string {
		return 'RfFieldsV2 #'.($i-$ColNums['RfFieldsV2']+1).' '.sprintf($Format, ...$Args);
	};

	//Handle misc/extra (RfFieldsV2) fields
	$Warnings=[];
	for($i=$ColNums['RfFieldsV2']; $i<count($Line); $i++) {
		//Pull in the parts of the misc field
		if(count($Parts=str_getcsv($Line[$i], ",", "\"", ""))!==5)
			return ['Error', $FormatError('is not in the correct format')];
		$Parts=(object)array_combine(['Name', 'Name3', 'Name2', 'Type', 'Value'], $Parts);
		if(!trim($Parts->Name))
			return ['Error', $FormatError('invalid blank name found')];

		//Figure out which “Names” to process
		$PartNames=[trim($Parts->Name)];
		foreach([$Parts->Name2, $Parts->Name3] as $NewName)
			if($NewName!=='' && !in_array($NewName, $PartNames))
				$PartNames[]=$NewName;

		//Process the different names
		foreach($PartNames as $PartName) {
			//Determined values for the item
			$LinkedID=null;
			$PartValue=$Parts->Value;
			/** @noinspection PhpUnusedLocalVariableInspection */ $Type=null; //Overwritten in all paths

			//Handle duplicate usernames and password fields
			if(($IsLogin=($PartValue===$C->Login)) || $PartValue===$C->Pwd) {
				if($Parts->Type!==($IsLogin ? 'txt' : 'pwd'))
					$Warnings[]=$FormatError('expected type “%s” but got “%s”', $IsLogin ? 'txt' : 'pwd', $Parts->Type);
				$Type=3;
				$PartValue=null;
				$LinkedID=($IsLogin ? 100 : 101);

				//If a common name then do not add the linked field
				/** @noinspection SpellCheckingInspection */
				if(in_array(
					strToLower($PartName),
					$IsLogin ?
						['user', 'username', 'login', 'email', 'userid', 'user id', 'user id$', 'user_email', 'login_email', 'user_login'] :
						['password', 'passwd', 'password$', 'pass', 'user_password', 'login_password', 'pwd', 'loginpassword']
				))
					continue;
			} else {
				//Convert the type
				switch($Parts->Type) {
					case '': //For some reason some text fields have no type given
					case 'rad':
					case 'sel':
					case 'txt': $Type=0; break;
					case 'pwd': $Type=1; break;
					case 'rck': //Radio check?
						$Type=0;
						if($PartName!==$Parts->Name) //Ignore second names for radio checks
							continue 2;
						if(count($RadioParts=explode(':', $PartName))!==2)
							return ['Error', $FormatError('radio name needs 2 parts separated by a colon')];
						$PartName=$RadioParts[0];
						$PartValue=$RadioParts[1];
						break;
					case 'chk':
						$Type=2;
						if($PartValue==='*' || $PartValue==='1')
							$PartValue='true';
						else if($PartValue==='0')
							$PartValue='false';
						else
							return ['Error', $FormatError('invalid value for chk type “%s”', $PartValue)];
						break;
					case 'are': //This seems to be a captcha
						continue 2;
					default:
						return ['Error', $FormatError('invalid field type “%s”', $Parts->Type)];
				}
			}

			//Create the return object
			if(!isset($Ret->fields))
				$Ret->fields=[];
			$Ret->fields[]=(object)[
				'name'=>$PartName,
				'value'=>$PartValue,
				'type'=>$Type,
				'linkedId'=>$LinkedID,
			];
		}
	}

	//Return finished item and warnings
	if(count($Warnings))
		$Warnings[0]="RfFieldsV2 warnings:\n".$Warnings[0];
	return ['Success', 'Data'=>$Ret, 'Warnings'=>$Warnings];
}

//Ask the user if they want to continue
function ConfirmContinue($Counts, $RowNum): ?string
{
	//Ask the user if they want to continue
	printf("Import from spreadsheet is finished. Total: %d; Successful: %d, Errors: %d, Warnings: %d\n", $RowNum, $Counts['Success'], $Counts['Error'], $Counts['Warning']);
	while(1) {
		print 'Do you wish to continue the export to bitwarden? (y/n): ';
		fflush(STDOUT);
		switch(trim(strtolower(fgets(STDIN)))) {
			case 'n':
				return 'Exiting';
			case 'y':
				break 2;
		}
	}

	return null;
}

//Get folder IDs and create parent folders for children
function GetFolders($FolderNames, &$FolderIDs): ?string
{
	foreach(array_keys($FolderNames) as $FolderName) {
		//Skip “No Folder”
		if($FolderName==='') {
			$FolderIDs[$FolderName]='';
			continue;
		}

		//Check each part of the folder tree to make sure it exists
		$FolderParts=explode('/', $FolderName);
		$CurPath='';
		foreach($FolderParts as $Index => $FolderPart) {
			$CurPath.=($Index>0 ? '/' : '').$FolderPart;

			//If folder is already cached then nothing to do
			if(isset($FolderIDs[$CurPath])) {
				continue;
			}

			//Create the folder
			print "Creating folder: $CurPath\n";
			if(is_string($FolderInfo=RunBW('create folder '.base64_encode(json_encode(['name'=>$CurPath])), 'create folder: '.$CurPath)))
				return $FolderInfo;
			else if (!isset($FolderInfo->id))
				return "bw folder create failed for “${CurPath}”: Return did not contain id";
			$FolderIDs[$CurPath]=$FolderInfo->id;
		}
	}
	return null;
}

//Pull the known items
function PullKnownItems($FoldersByID): string|array
{
	$CurItems=[];
	if(is_string($ItemList=RunBW('list items')))
		return $ItemList;
	foreach($ItemList as $Item) {
		$CurItems[($Item->folderId===null ? '' : $FoldersByID[$Item->folderId].'/').$Item->name]=$Item->id;
	}
	return $CurItems;
}

function StoreItems($Items, $CurItems, $FoldersByID): int
{
	print "\n"; //Give an extra newline before starting the processing
	$NumItems=count($Items);
	$NumErrors=0;
	$HandleDuplicatesAction=''; //The "always do this" action
	foreach($Items as $Index => $Item) {
		//Clear the current line and print the processing status
		printf("\rProcessing item #%d/%d", $Index+1, $NumItems);
		fflush(STDOUT);

		//If the item already exists then request what to do
		$FullItemName=($Item->folderId===null ? '' : $FoldersByID[$Item->folderId].'/').$Item->name;
		if(isset($CurItems[$FullItemName])) {
			//If no "always" action has been set in $HandleDuplicatesAction then ask the user what to do
			if(!($Action=$HandleDuplicatesAction)) {
				print "\n";
				while(1) {
					print "A duplicate at “${FullItemName}” was found. Choose your action (s=skip,o=overwrite,S=always skip,O=always overwrite):";
					fflush(STDOUT);
					$Val=trim(fgets(STDIN));
					if(in_array(strtolower($Val), ['s', 'o']))
						break;
				}
				$Action=strtolower($Val);
				if($Val!==$Action) //Upper case sets the "always" state
					$HandleDuplicatesAction=$Action;
			}

			//Skip the item
			if($Action==='s')
				continue;

			//Overwrite the item
			if(is_string($Ret=RunBW('edit item '.$CurItems[$FullItemName].' '.base64_encode(json_encode($Item)), 'edit item: '.$FullItemName))) {
				$NumErrors++;
				printf("\nError on item #%d: %s\n", $Index+1, $Ret);
			}
			continue;
		}

		//Create the item
		if(is_string($Ret=RunBW('create item '.base64_encode(json_encode($Item)), 'create item: '.$FullItemName))) {
			$NumErrors++;
			printf("\nError on item #%d: %s\n", $Index+1, $Ret);
		}
	}
	return $NumErrors;
}

//Run a command through the “bw” application
function RunBW($Command, $Label=null, $DecodeJSON=true): string|object|array
{
	//$Label is set to $Command if not given
	if($Label===null)
		$Label=$Command;

	//Run the command and check the results
	global $BW_SESSION;
	exec(sprintf('BW_SESSION=%s bw %s --pretty 2>&1', $BW_SESSION, $Command), $Output, $ResultCode);
	if($ResultCode===127)
		return 'bw is not installed';
	else if($ResultCode===1)
		return "bw “${Label}” threw an error: \n".implode("\n", $Output);
	else if($ResultCode!==0)
		return "bw “${Label}” returned an invalid status code [$ResultCode]: \n".implode("\n", $Output);
	else if($Output[0]==='mac failed.')
		return 'Invalid session ID';
	else if(!$DecodeJSON)
		return [implode("\n", $Output)];
	else if(($JsonRet=json_decode(implode("\n", $Output)))===null)
		return "bw “${Label}” returned non-json result";

	//Return the json object
	return $JsonRet;
}

function RunMe($argv): string
{
	//Make sure “bw” is installed and given session is valid
	if(is_string($Ret=CheckBWStatus()))
		return $Ret;

	//Pull the known folders
	if(is_string($FolderIDs=PullKnownFolders()))
		return $FolderIDs;

	//Get the file handle and the column indexes
	if(is_string($Ret=GetFileAndColumns()))
		return $Ret;
	[$f, $ColNums]=$Ret;
	unset($argv);

	//Process the rows and ask the user if they want to continue
	[$Counts, $FolderNames, $Items, $RowNum]=ProcessRows($f, $ColNums, $FolderIDs);
	if(is_string($Ret=ConfirmContinue($Counts, $RowNum)))
		return $Ret;
	unset($Counts, $RowNum, $f, $ColNums);

	//Get folder IDs and create parent folders for children
	if(is_string($Ret=GetFolders($FolderNames, $FolderIDs)))
		return $Ret;
	unset($FolderNames);

	//Pull the known items
	$FoldersByID=array_flip($FolderIDs);
	if(is_string($CurItems=PullKnownItems($FoldersByID)))
		return $CurItems;

	//Store all items
	$FolderIDs['']=null; //Change empty folder to id=null for json insertions
	$NumErrors=StoreItems($Items, $CurItems, $FoldersByID);

	//Return completion information
	return "\nCompleted ".($NumErrors ? "with $NumErrors error(s)" : 'successfully');
}