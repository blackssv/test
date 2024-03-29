<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

class CBPUniversalAction
    extends CBPActivity
{
	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = array(
			"Title" => "",
			"CalendarUser" => "",
			"CalendarName" => "",
			"CalendarDesrc" => "",
			"CalendarFrom" => "",
			"CalendarTo" => "",
			"CalendarTimezone" => "",
			"CalendarType" => "",
			"CalendarOwnerId" => "",
			"CalendarSection" => "",
			"GenerateICSFile" => "",
			"ICSFile" => ""
		);
	}

	public function Execute()
	{
		if (!CModule::IncludeModule("calendar"))
			return CBPActivityExecutionStatus::Closed;

		$rootActivity = $this->GetRootActivity();
		$documentId = $rootActivity->GetDocumentId();
		$documentService = $this->workflow->GetService("DocumentService");

		$fromTs = CCalendar::Timestamp($this->CalendarFrom);
		$toTs = $this->CalendarTo == '' ? $fromTs : CCalendar::Timestamp($this->CalendarTo);

		$arFields = array(
			"CAL_TYPE" => !$this->CalendarType ? 'user' : $this->CalendarType,
			"NAME" => trim($this->CalendarName) == '' ? GetMessage('EC_DEFAULT_EVENT_NAME') : $this->CalendarName,
			"DESCRIPTION" => $this->CalendarDesrc,
			"SKIP_TIME" => date('H:i', $fromTs) == '00:00' && date('H:i', $toTs) == '00:00',
			"IS_MEETING" => false,
			"RRULE" => false,
			"TZ_FROM" => $this->CalendarTimezone,
			"TZ_TO" => $this->CalendarTimezone
		);

		if ($fromTs == $toTs && !$arFields["SKIP_TIME"])
			$toTs += 3600 /* HOUR LENGTH*/;

		$arFields['DATE_FROM'] = CCalendar::Date($fromTs);
		$arFields['DATE_TO'] = CCalendar::Date($toTs);

		if ($this->CalendarSection && intVal($this->CalendarSection) > 0)
		{
			$arFields['SECTIONS'] = array(intVal($this->CalendarSection));
		}

		if ($this->CalendarOwnerId || ($arFields["CAL_TYPE"] != "user" && $arFields["CAL_TYPE"] != "group"))
		{
			$arFields["OWNER_ID"] = $this->CalendarOwnerId;
			if (!$arFields['SKIP_TIME'] && !$this->CalendarTimezone)
			{
				unset($arFields["TZ_FROM"], $arFields["TZ_TO"]);
			}
			CCalendar::SaveEvent(
				array(
					'userId' => CBPHelper::ExtractUsers($this->CalendarUser, $documentId, true),
					'arFields' => $arFields,
					'autoDetectSection' => true,
					'autoCreateSection' => true
				)
			);
		}
		else
		{
			$arCalendarUser = CBPHelper::ExtractUsers($this->CalendarUser, $documentId);
			foreach ($arCalendarUser as $calendarUser)
			{
				$arFields["CAL_TYPE"] = "user";
				$arFields["OWNER_ID"] = $calendarUser;

				if (!$arFields['SKIP_TIME'] && !$this->CalendarTimezone)
				{
					$tzName = CCalendar::GetUserTimezoneName($calendarUser);
					$arFields["TZ_FROM"] = $arFields["TZ_TO"] = $tzName;
				}

				CCalendar::SaveEvent(
					array(
						'arFields' => $arFields,
						'autoDetectSection' => true
					)
				);
			}
		}

		if(preg_match('#Y#',$this->GenerateICSFile)){
			$arFields['DSTART']=date('Ymd\THis',$fromTs);
			$arFields['DEND']=date('Ymd\THis',$toTs);
			preg_match('#\=([^\:]+)\:([A-Za-z_\-]+)#',$this->getRawProperty('ICSFile'),$m);
			switch($m[1]){
				case 'Variable':
					$this->SetVariable($m[2], $this->GenerateICS($arFields));
					break;
				case 'Template':
					break;
				case 'Document':
					break;
				
			}
		}
		return CBPActivityExecutionStatus::Closed;
	}

	public function GenerateICS($arFields = array(
		'DSTART'=>'',
		'DEND'=>'',
		'FROM_NAME'=>'',
		'FROM_EMAIL'=>'',
		'NAME'=>'',
		'DESCRIPTION'=>''
	))
	{
		
		//$UID = $this->GetName();
		$UID = randString(7);
		$DTSTAMP = date('Ymd\THis');
		$path = \Bitrix\Main\Application::getInstance()->getDocumentRoot() . "/upload/temp/".$UID.".txt";
		$file = new \Bitrix\Main\IO\File($path);
		$result=
			"BEGIN:VCALENDAR\n"
			."VERSION:2.0\n"
			."PRODID:-//hacksw/handcal//NONSGML v1.0//EN\n"
			."BEGIN:VEVENT\n"
			."UID:{$UID}\n"
			."DTSTAMP:{$DTSTAMP}Z\n"
			."DESCRIPTION:{$arFields['DESCRIPTION']}\n"
			."DTSTART:{$arFields['DSTART']}Z\n"
			."DTEND:{$arFields['DEND']}Z\n"
			."SUMMARY:{$arFields['NAME']}\n"
			."END:VEVENT\n"
			."END:VCALENDAR";
		$file->putContents($result);
		$arFile = CFile::MakeFileArray($path);
		$FID = CFile::SaveFile($arFile, "bp");
		return $FID;
	}

	public static function ValidateProperties($arTestProperties = array(), CBPWorkflowTemplateUser $user = null)
	{
		$arErrors = array();

		if (!array_key_exists("CalendarUser", $arTestProperties) || count($arTestProperties["CalendarUser"]) <= 0)
			$arErrors[] = array("code" => "NotExist", "parameter" => "CalendarUser", "message" => GetMessage("BPSNMA_EMPTY_CALENDARUSER"));
		if (!array_key_exists("CalendarName", $arTestProperties) || $arTestProperties["CalendarName"] == '')
			$arErrors[] = array("code" => "NotExist", "parameter" => "CalendarName", "message" => GetMessage("BPSNMA_EMPTY_CALENDARNAME"));
		if (!array_key_exists("CalendarFrom", $arTestProperties) || $arTestProperties["CalendarFrom"] == '')
			$arErrors[] = array("code" => "NotExist", "parameter" => "CalendarFrom", "message" => GetMessage("BPSNMA_EMPTY_CALENDARFROM"));

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	public static function GetPropertiesDialog($documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters, $arWorkflowVariables, $arCurrentValues = null, $formName = "")
	{
		global $USER;
		CModule::IncludeModule("calendar");
		$runtime = CBPRuntime::GetRuntime();

		$arMap = array(
			"CalendarType" => "calendar_type",
			"CalendarOwnerId" => "calendar_owner_id",
			"CalendarSection" => "calendar_section",
			"CalendarUser" => "calendar_user",
			"CalendarName" => "calendar_name",
			"CalendarDesrc" => "calendar_desrc",
			"CalendarFrom" => "calendar_from",
			"CalendarTo" => "calendar_to",
			"CalendarTimezone" => "calendar_timezone",
			"GenerateICSFile" => "GenerateICSFile",
			"ICSFile" => "ICSFile"
		);

		if (!is_array($arWorkflowParameters))
			$arWorkflowParameters = array();
		if (!is_array($arWorkflowVariables))
			$arWorkflowVariables = array();

		if (!is_array($arCurrentValues))
		{
			$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
			if (is_array($arCurrentActivity["Properties"]))
			{
				foreach ($arMap as $k => $v)
				{
					if (array_key_exists($k, $arCurrentActivity["Properties"]))
					{
						if ($k == "CalendarUser")
							$arCurrentValues[$arMap[$k]] = CBPHelper::UsersArrayToString($arCurrentActivity["Properties"][$k], $arWorkflowTemplate, $documentType);
						else
							$arCurrentValues[$arMap[$k]] = $arCurrentActivity["Properties"][$k];
					}
					else
					{
						$arCurrentValues[$arMap[$k]] = "";
					}
				}
			}
			else
			{
				foreach ($arMap as $k => $v)
					$arCurrentValues[$arMap[$k]] = "";
			}
		}

		if (!$arCurrentValues["calendar_timezone"])
		{
			$userId = $USER->GetId();
			$tzName = CCalendar::GetUserTimezoneName($userId);
			$arCurrentValues["calendar_timezone"] = $tzName;
		}

		return $runtime->ExecuteResourceFile(
			__FILE__,
			"properties_dialog.php",
			array(
				"arCurrentValues" => $arCurrentValues,
				"formName" => $formName,
				"timezoneList" => CCalendar::GetTimezoneList()
			)
		);
	}

	public static function GetPropertiesDialogValues($documentType, $activityName, &$arWorkflowTemplate, &$arWorkflowParameters, &$arWorkflowVariables, $arCurrentValues, &$arErrors)
	{
		$arErrors = array();

		$runtime = CBPRuntime::GetRuntime();

		$arMap = array(
			"calendar_user" => "CalendarUser",
			"calendar_name" => "CalendarName",
			"calendar_desrc" => "CalendarDesrc",
			"calendar_from" => "CalendarFrom",
			"calendar_to" => "CalendarTo",
			"calendar_type" => "CalendarType",
			"calendar_owner_id" => "CalendarOwnerId",
			"calendar_section" => "CalendarSection",
			"calendar_timezone" => "CalendarTimezone",
			"GenerateICSFile" => "GenerateICSFile",
			"ICSFile" => "ICSFile"
		);

		$arProperties = array();
		foreach ($arMap as $key => $value)
		{
			if ($key == "calendar_user")
				continue;
			$arProperties[$value] = $arCurrentValues[$key];
		}

		$arProperties["CalendarUser"] = CBPHelper::UsersStringToArray($arCurrentValues["calendar_user"], $documentType, $arErrors);
		if (count($arErrors) > 0)
			return false;

		$arErrors = self::ValidateProperties($arProperties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));
		if (count($arErrors) > 0)
			return false;

		$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$arCurrentActivity["Properties"] = $arProperties;

		return true;
	}
}
?>