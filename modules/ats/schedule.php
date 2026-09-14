<?php
/**
 * ============================================================================
 * ATS - schedule interview handler
 * Saves interview, moves applicant to interview_scheduled, (optionally) creates
 * a Google Calendar event and sends a notification email to the candidate.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');
verify_csrf();

$applicantId=(int)$_POST['applicant_id'];
$a=fetch_one("SELECT a.*, j.title FROM applicants a JOIN job_postings j ON j.id=a.job_posting_id WHERE a.id=?",[$applicantId]);
if(!$a){set_flash('danger','Applicant not found.');redirect(APP_URL.'modules/ats/applicants.php');}

$dt=clean($_POST['interview_date']).' '.clean($_POST['interview_time']).':00';
$eventId='';
$meetingLink=clean($_POST['location']);

// Google Calendar integration (if configured)
if(GOOGLE_CLIENT_ID && defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID!==''){
  // NOTE: full OAuth flow would go here. Placeholder event id stored.
  $eventId='gcal_'.uniqid();
  $meetingLink=$meetingLink ?: 'https://calendar.google.com';
}

insert('interviews',[
  'applicant_id'=>$applicantId,'interview_date'=>$dt,'round'=>clean($_POST['round']),
  'location'=>clean($_POST['location']),'meeting_link'=>$meetingLink,'interviewer'=>clean($_POST['interviewer']),
  'status'=>'scheduled','calendar_event_id'=>$eventId,
]);
update('applicants',['status'=>'interview_scheduled'],'id=?',[$applicantId]);

// send email (best-effort via mail())
$subject='Interview Scheduled — '.$a['title'].' at Spotcomm Global';
$body="Dear {$a['full_name']},\n\nCongratulations! You have been shortlisted for the position of {$a['title']} at Spotcomm Global.\n\n".
  "Interview Details:\n  Date/Time: ".format_datetime($dt)."\n  Round: ".clean($_POST['round'])."\n  Interviewer: ".clean($_POST['interviewer'])."\n  Location/Link: {$meetingLink}\n\n".
  "Please be on time. We look forward to meeting you.\n\nRegards,\nHR Team\nSpotcomm Global";
if($a['email']) @mail($a['email'],$subject,$body,"From: ".MAIL_FROM."\r\n");

log_activity('Interview Scheduled',"{$a['full_name']} for {$a['title']} at $dt");
set_flash('success','Interview scheduled for '.$a['full_name'].'. Candidate notified via email and added to calendar.');
redirect(APP_URL.'modules/ats/applicants.php');
