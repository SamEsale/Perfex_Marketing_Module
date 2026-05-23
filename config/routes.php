<?php

defined('BASEPATH') or exit('No direct script access allowed');

$route['marketing/webhooks/ses_sns'] = 'webhooks/ses_sns';
$route['marketing/webhooks/inbound_email'] = 'webhooks/inbound_email';
$route['marketing/webhooks/whatsapp'] = 'webhooks/whatsapp';
$route['marketing/track/c/(:any)'] = 'track/c/$1';
$route['marketing/unsubscribe/(:any)'] = 'subscriptions/unsubscribe/$1';
$route['admin/marketing/campaigns/reprepare_recipients/(:num)'] = 'campaigns/reprepare_recipients/$1';
$route['marketing/campaigns/preview/(:num)'] = 'campaigns/preview/$1';
$route['marketing/campaigns/reprepare_recipients/(:num)'] = 'campaigns/reprepare_recipients/$1';
$route['marketing/campaigns/schedule/(:num)'] = 'campaigns/schedule/$1';
$route['marketing/campaigns/upload_media'] = 'campaigns/upload_media';
$route['marketing/settings'] = 'settings/index';
$route['marketing/settings/save'] = 'settings/save';
$route['marketing/settings/save_whatsapp_settings'] = 'settings/save_whatsapp_settings';
$route['marketing/settings/test_smtp'] = 'settings/test_smtp';
$route['marketing/settings/test_reply_mailbox'] = 'settings/test_reply_mailbox';
$route['marketing/settings/test_whatsapp'] = 'settings/test_whatsapp';
