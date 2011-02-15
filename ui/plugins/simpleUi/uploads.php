<?php
/*
 Copyright (C) 2011 Hewlett-Packard Development Company, L.P.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/**
 * \brief
 *
 * @version "$Id $"
 * Created on Feb 11, 2011 by Mark Donohoe
 */

define("TITLE_uploads", _("Uploads"));

class uploads extends FO_Plugin
{
  public $Name = "uploads";
  public $Title = TITLE_uploads;
  public $version = "1.0";
  public $MenuList = "Uploads";
  //public $MenuTarget = "uploadajax";
  public $Dependency = array("db", "agent_unpack");
  public $DBaccess = PLUGIN_DB_UPLOAD;

  function uploadFile($Folder, $TempFile, $Name)
  {
    //echo "<pre>AUP: in upload\n</pre>";

    /* See if the folder looks valid */
    if (empty($Folder)) {
      $text = _("Invalid folder");
      return ($text);
    }
    if (empty($Name)) {
      $Name = basename(@$_FILES['getfile']['name']);
    }
    $originName = @$_FILES['getfile']['name'];
    $ShortName = basename($Name);
    if (empty($ShortName)) {
      $ShortName = $Name;
    }
    /* Create an upload record. */
    $Mode = (1 << 3); // code for "it came from web upload"
    $uploadpk = JobAddUpload($ShortName, $originName, $Desc, $Mode, $Folder);
    if (empty($uploadpk)) {
      $text = _("Failed to insert upload record");
      return ($text);
    }
    /* move the temp file */
    //echo "<pre>uploadfile: renaming uploaded file\n</pre>";
    if (!move_uploaded_file($TempFile, "$TempFile-uploaded")) {
      $text = _("Could not save uploaded file");
      return ($text);
    }
    $UploadedFile = "$TempFile" . "-uploaded";
    //echo "<pre>uploadfile: \$UploadedFile is:$UploadedFile\n</pre>";
    if (!chmod($UploadedFile, 0660)) {
      $text = _("ERROR! could not update permissions on downloaded file");
      return ($text);
    }

    /* Run wget_agent locally to import the file. */

    global $LIBEXECDIR;

    $Prog = "$LIBEXECDIR/agents/wget_agent -g fossy -k $uploadpk '$UploadedFile'";
    $wgetLast = exec($Prog,$wgetOut,$wgetRtn);
    unlink($UploadedFile);

    global $Plugins;

    $Unpack = &$Plugins[plugin_find_id("agent_unpack") ];

    $jobqueuepk = NULL;
    $Unpack->AgentAdd($uploadpk, array($jobqueuepk));
    userDefaultAgents($uploadpk);

    if($wgetRtn == 0) {
      $text = _("The file");
      $text1 = _("has been uploaded. It is");
      $Url = Traceback_uri() . "?mod=showjobs&history=1&upload=$uploadpk";
      $Msg = "$text $Name $text1 ";
      $keep = '<a href=' . $Url . '>upload #' . $uploadpk . "</a>.\n";
      print displayMessage($Msg,$keep);
      return (NULL);
    }
    else {
      return($wgetOut[0]);
    }
    return(NULL);
  } // uploadFile


  /**
   * \brief uploadSrv: process the upload from server request, scheduling
   * agents as needed.
   */

  /**
   *
   * Function: uploadSrv()
   *
   * \brief Process the upload from server request.  Call the upload by the
   * Name passed in or by the filename if no name is supplied.
   *
   * @param int $FolderPk folder fk to load into
   * @param string $SourceFiles files to upload, file, tar, directory, etc...
   * @param string $GroupNames flag for indicating if group names were requested.
   *        passed on as -A option to cp2foss.
   * @param string $Name optional Name for the upload
   *
   * @return NULL on success, string on failure.
   */
  function uploadSrv($FolderPk, $SourceFiles, $GroupNames, $Name)
  {

    global $LIBEXECDIR;
    global $DB;
    global $Plugins;

    $FolderPath = FolderGetName($FolderPk);
    $CMD = "";
    if ($GroupNames == "1")
    {
      $CMD.= " -A";
    }
    $FolderPath = str_replace('`', '\`', $FolderPath);
    $FolderPath = str_replace('$', '\$', $FolderPath);
    $CMD.= " -f \"$FolderPath\"";
    if (!empty($Name))
    {
      $Name = str_replace('`', '\`', $Name);
      $Name = str_replace('$', '\$', $Name);
      $CMD.= " -n \"$Name\"";
    }
    else
    {
      $Name = $SourceFiles;
    }

    // get the default agents selected by the user, as simple screen does not
    // have user choices shown.
    $userName = $_SESSION['User'];
    $SQL = "SELECT user_name, user_agent_list FROM users WHERE
            user_name='$userName';";
    $uList = $DB->Action($SQL);

    // Ulist can be empty if the user does not have the correct permissions
    // or has not selected any default/preferred agents or sql failed.
    if(empty($uList))
    {
      return;       // nothing to schedule or sql failed....

    }
    $alist = $uList[0]['user_agent_list'];
    $agentList = " -q " . $alist;
    $CMD .= $agentList;

    $SourceFiles = str_replace('`', '\`', $SourceFiles);
    $SourceFiles = str_replace('$', '\$', $SourceFiles);
    $SourceFiles = str_replace('|', '\|', $SourceFiles);
    $SourceFiles = str_replace(' ', '\ ', $SourceFiles);
    $SourceFiles = str_replace("\t", "\\\t", $SourceFiles);
    $CMD.= " $SourceFiles";
    $jq_args = trim($CMD);
    /* Add the job to the queue */
    // create the job
    $ShortName = basename($Name);
    if (empty($ShortName)) {
      $ShortName = $Name;
    }
    echo "<pre>UPSRV: name is:$Name\nShortName is:$ShortName\n</pre>";
    // Create an upload record.
    $jobq = NULL;
    $Mode = (1 << 3); // code for "it came from web upload"
    $uploadpk = JobAddUpload($ShortName, $SourceFiles, $Desc, $Mode, $FolderPk);
    $jobq = JobAddJob($uploadpk, 'fosscp_agent', 0);
    if (empty($jobq))
    {
      $text = _("Failed to create job record");
      return ($text);
    }

    /* Check for email notification and adjust jq_args as needed */
    if (CheckEnotification())
    {
      if(empty($_SESSION['UserEmail']))
      {
        $Email = 'fossy@localhost';
      }
      else
      {
        $Email = $_SESSION['UserEmail'];
      }
      /*
       * Put -w webServer -e <addr> in the front as the upload is last
       * part of jq_args.
       */
      $jq_args = " -W {$_SERVER['SERVER_NAME']} -e $Email " . "$jq_args";
    }
    // put the job in the jobqueue
    $jq_type = 'fosscp_agent';
    $jobqueue_pk = JobQueueAdd($jobq, $jq_type, $jq_args, "no", NULL, NULL, 0);

    if (empty($jobqueue_pk))
    {
      $text = _("Failed to place fosscp_agent in job queue");
      return ($text);
    }
    $Url = Traceback_uri() . "?mod=showjobs&history=1&upload=$uploadpk";
    $msg = "The upload for $SourceFiles has been scheduled. ";
    $keep = "It is <a href='$Url'>upload #" . $uploadpk . "</a>.\n";
    print displayMessage($msg,$keep);
    return (NULL);
  } // uploadSrv()

  /**
   * \brief uploadUrl(): Process the upload from URL request.
   *
   * @return NULL on success, string on failure.
   */

  function uploadUrl($Folder, $GetURL, $Desc, $Name)
  {

    if (empty($Folder))
    {
      $text = _("Invalid folder");
      return ($text);
    }
    if (empty($GetURL))
    {
      $text = _("Invalid URL");
      return ($text);
    }
    /* See if the URL looks valid */
    if (preg_match("@^((http)|(https)|(ftp))://([[:alnum:]]+)@i", $GetURL) != 1)
    {
      $text = _("Invalid URL");
      return ("$text: " . htmlentities($GetURL));
    }
    if (preg_match("@[[:space:]]@", $GetURL) != 0)
    {
      $text = _("Invalid URL (no spaces permitted)");
      return ("$text: " . htmlentities($GetURL));
    }
    if (empty($Name))
    {
      $Name = basename($GetURL);
    }
    $ShortName = basename($Name);
    if (empty($ShortName))
    {
      $ShortName = $Name;
    }
    /* Create an upload record. */
    $Mode = (1 << 2); // code for "it came from wget"
    $uploadpk = JobAddUpload($ShortName, $GetURL, $Desc, $Mode, $Folder);
    if (empty($uploadpk))
    {
      $text = _("Failed to insert upload record");
      return ($text);
    }
    /* Prepare the job: job "wget" */
    $jobpk = JobAddJob($uploadpk, "wget");
    if (empty($jobpk) || ($jobpk < 0))
    {
      $text = _("Failed to insert job record");
      return ($text);
    }
    /* Prepare the job: job "wget" has jobqueue item "wget" */
    /** 2nd parameter is obsolete **/
    $jobqueuepk = JobQueueAdd($jobpk, "wget", "$uploadpk - $GetURL", "no", NULL, NULL);
    if (empty($jobqueuepk))
    {
      $text = _("Failed to insert task 'wget' into job queue");
      return ($text);
    }
    global $Plugins;
    $Unpack = & $Plugins[plugin_find_id("agent_unpack") ];
    $Unpack->AgentAdd($uploadpk, array($jobqueuepk));

    userDefaultAgents($uploadpk);

    $Url = Traceback_uri() . "?mod=showjobs&history=1&upload=$uploadpk";
    $text = _("The upload");
    $text1 = _("has been scheduled. It is");
    $msg = "$text $Name $text1 ";
    $keep =  "<a href='$Url'>upload #" . $uploadpk . "</a>.\n";
    print displayMessage($msg,$keep);
    return (NULL);
  } // uploadUrl()

  function Output()
  {
    if ($this->State != PLUGIN_STATE_READY)
    {
      return;
    }
    $Buttons = "";
    switch ($this->OutputType)
    {
      case "XML":
        break;
      case "HTML":
        $formName = GetParm('uploadform', PARM_TEXT); // may be null
        //echo "<pre>formName from get is:$formName\n</pre>";
        if($formName == 'fileupload')
        {
          // If this is a POST, then process the request.
          $Folder = GetParm('folder', PARM_INTEGER);
          $Name = GetParm('name', PARM_TEXT); // may be null
          if (file_exists(@$_FILES['getfile']['tmp_name']) && !empty($Folder))
          {
            $uf = @$_FILES['getfile']['tmp_name'];
            $rc = $this->uploadFile($Folder, @$_FILES['getfile']['tmp_name'], $Name);
            if (empty($rc))
            {
              // reset form fields
              $GetURL = NULL;
              $Desc = NULL;
              $Name = NULL;
            }
            else
            {
              $text = _("Upload failed for file");
              $V.= displayMessage("$text {$_FILES[getfile][name]}: $rc");
            }
          }
        }
        else if($formName == 'urlupload')
        {
          /* If this is a POST, then process the request. */
          $Folder = GetParm('folder', PARM_INTEGER);
          $GetURL = GetParm('geturl', PARM_TEXT);
          $Name = GetParm('name', PARM_TEXT); // may be null
          if (!empty($GetURL) && !empty($Folder))
          {
            $rc = $this->uploadUrl($Folder, $GetURL, $Desc, $Name);
            if (empty($rc))
            {
              /* Need to refresh the screen */
              $GetURL = NULL;
              $Desc = NULL;
              $Name = NULL;
            }
            else
            {
              $text = _("Upload failed for");
              $V.= displayMessage("$text $GetURL: $rc");
            }
          }
        }
        else if($formName == 'srvupload')
        {
          /* If this is a POST, then process the request. */
          $SourceFiles = GetParm('sourcefiles', PARM_STRING);
          $GroupNames = GetParm('groupnames', PARM_INTEGER);
          $FolderPk = GetParm('folder', PARM_INTEGER);
          $Name = GetParm('name', PARM_STRING); // may be null
          if (!empty($SourceFiles) && !empty($FolderPk))
          {
            $rc = $this->uploadSrv($FolderPk, $SourceFiles, $GroupNames, $Name);
            if (empty($rc))
            {
              // clear form fileds
              $SourceFiles = NULL;
              $GroupNames  = NULL;
              $FolderPk    = NULL;
              $Desc        = NULL;
              $Name        = NULL;
            }
            else
            {
              $text = _("Upload failed for");
              $V.= displayMessage("$text $SourceFiles: $rc");
            }
          }
        }

        $Url = Traceback_uri();
        $intro .= _("FOSSology has many options for importing and uploading files for analysis.\n");
        $intro .= _("The options vary based on <i>where</i> the data to upload is located.\n");
        $intro .= _("The data may be located:\n");
        $intro .= "<ul>\n";
        $text = _("On your browser system");
        $intro .= "<li><b>$text</b>.\n";
        $text = _("Use the");
        $text1 = _("Upload File");
        $text2 = _("option to select and upload the file.");
        $intro .= "$text <a href='${Uri}?mod=ajax_fileUpload'>$text1</a> $text2\n";
        $intro .= _("While this can be very convenient (particularly if the file is not readily accessible online),\n");
        $intro .= _("uploading via your web browser can be slow for large files,\n");
        $intro .= _("and files larger than 650 Megabytes may not be uploadable.\n");
        $intro .= "<P />\n";
        $text = _("On a remote server");
        $intro .= "<li><b>$text</b>.\n";
        $text = _("Use the");
        $text1 = _("Upload from URL");
        $text2 = _("option to specify a remote server.");
        $intro .= "$text <a href='${Uri}?mod=upload_url'>$text1</a> $text2\n";
        $intro .= _("This is the most flexible option, but the URL must denote a publicly accessible HTTP, HTTPS, or FTP location.\n");
        $intro .= _("URLs that require authentication or human interactions cannot be downloaded through this automated system.\n");
        $intro .= "<P />\n";
        $choice .= $intro;
        //$choice .= "<br>\n";
        $choice .= "<form name='uploads' enctype='multipart/form-data' method='post'>\n";
        $choice .= "<input type='checkbox' name='Check_upload_file' value='file' onclick='UploadFile_Get(\"" .Traceback_uri() . "?mod=ajax_fileUpload\")' />Upload a File from your computer<br />\n";
        $choice .= "<input type='checkbox' name='Check_upload_url' value='url' onclick='UploadUrl_Get(\"" .Traceback_uri() . "?mod=ajax_urlUpload\")' />Upload from a URL on the intra or internet<br />\n";
        $choice .= "<input type='checkbox' name='Check_Opts' value='opts' onclick='UploadOpts_Get(\"" .Traceback_uri() . "?mod=ajax_optsForm\")' />More Options<br />\n";

        $choice .= "\n<div>\n
                   <hr>
                   <p id='fileform'></p>
                   </div>";
        /* Create the AJAX (Active HTTP) javascript for doing the replys
         * and showing the response.
         */
        $choice .= ActiveHTTPscript("UploadFile");
        $choice .= "<script language='javascript'>\n
        function UploadFile_Reply()
        {
          if ((UploadFile.readyState==4) && (UploadFile.status==200))
          {\n
            /* Remove all options */
            document.getElementById('fileform').innerHTML = UploadFile.responseText;\n
            /* Add new options */
          }
        }
        </script>\n";

        // URL's
        $choiceUrl .= ActiveHTTPscript("UploadUrl");
        $choiceUrl .= "<script language='javascript'>\n
        function UploadUrl_Reply()
        {
          if ((UploadUrl.readyState==4) && (UploadUrl.status==200))
          {\n
            /* Remove all options */
            document.getElementById('fileform').innerHTML = UploadUrl.responseText;\n
            /* Add new options */
          }
        }
        </script>\n";
        $choice .= $choiceUrl;

        // More Options

        $options .= ActiveHTTPscript("UploadOpts");
        $options .= "<script language='javascript'>\n
        function UploadOpts_Reply()
        {
          if ((UploadOpts.readyState==4) && (UploadOpts.status==200))
          {\n
            /* Remove all options */
            document.getElementById('fileform').innerHTML = UploadOpts.responseText;\n
            /* Add new options */
          }
        }
        </script>\n";
        $choice .= $options;

        // upload from server
        $uploadSrv .= ActiveHTTPscript("UploadSrv");
        $uploadSrv .= "<script language='javascript'>\n
        function UploadSrv_Reply()
        {
          if ((UploadSrv.readyState==4) && (UploadSrv.status==200))
          {\n
            /* Remove all options */
            document.getElementById('optsform').innerHTML = UploadSrv.responseText;\n
            /* Add new options */
          }
        }
        </script>\n";
        $choice .= $uploadSrv;

        // upload from server
        $UploadOsN .= ActiveHTTPscript("UploadOsN");
        $UploadOsN .= "<script language='javascript'>\n
        function UploadOsN_Reply()
        {
          if ((UploadOsN.readyState==4) && (UploadOsN.status==200))
          {\n
            /* Remove all options */
            document.getElementById('optsform').innerHTML = UploadOsN.responseText;\n
            /* Add new options */
          }
        }
        </script>\n";
        $choice .= $uploadSrv;
        $choice .= "</form>";
        break;
  case "Text":
    break;
  default:
    break;
}
if (!$this->OutputToStdout)
{
  return ($choice);
}
print ("$choice");
return;

}
};
$NewPlugin = new uploads;

?>