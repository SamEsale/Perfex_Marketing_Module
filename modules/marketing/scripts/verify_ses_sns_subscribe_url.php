#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASEPATH', __DIR__);

require_once dirname(__DIR__) . '/libraries/Marketing_ses_sns.php';

final class MarketingSesSnsSubscribeUrlProbe extends Marketing_ses_sns
{
    public function validate_subscribe_url(string $url, string $region = '', string $topicArn = ''): bool
    {
        return $this->is_valid_sns_action_url($url, $region, $topicArn);
    }
}

$probe = new MarketingSesSnsSubscribeUrlProbe();
$topicArn = 'arn:aws:sns:us-east-1:089089715851:eusaconevents-ses-topic';
$validUrl = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='
    . rawurlencode($topicArn)
    . '&Token=test-token';

$cases = [
    [
        'name' => 'accepts valid AWS SNS ConfirmSubscription URL',
        'expected' => true,
        'url' => $validUrl,
        'region' => 'us-east-1',
        'topic_arn' => $topicArn,
    ],
    [
        'name' => 'rejects http SubscribeURL',
        'expected' => false,
        'url' => str_replace('https://', 'http://', $validUrl),
        'region' => 'us-east-1',
        'topic_arn' => $topicArn,
    ],
    [
        'name' => 'rejects non-AWS host',
        'expected' => false,
        'url' => str_replace('sns.us-east-1.amazonaws.com', 'example.com', $validUrl),
        'region' => 'us-east-1',
        'topic_arn' => $topicArn,
    ],
    [
        'name' => 'rejects wrong Action',
        'expected' => false,
        'url' => str_replace('Action=ConfirmSubscription', 'Action=Subscribe', $validUrl),
        'region' => 'us-east-1',
        'topic_arn' => $topicArn,
    ],
    [
        'name' => 'rejects missing Token',
        'expected' => false,
        'url' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=' . rawurlencode($topicArn),
        'region' => 'us-east-1',
        'topic_arn' => $topicArn,
    ],
    [
        'name' => 'rejects wrong region',
        'expected' => false,
        'url' => str_replace('sns.us-east-1.amazonaws.com', 'sns.eu-west-1.amazonaws.com', $validUrl),
        'region' => 'us-east-1',
        'topic_arn' => $topicArn,
    ],
    [
        'name' => 'rejects mismatched TopicArn when configured',
        'expected' => false,
        'url' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='
            . rawurlencode('arn:aws:sns:us-east-1:089089715851:other-topic')
            . '&Token=test-token',
        'region' => 'us-east-1',
        'topic_arn' => $topicArn,
    ],
];

$failures = 0;

foreach ($cases as $case) {
    $actual = $probe->validate_subscribe_url($case['url'], $case['region'], $case['topic_arn']);
    if ($actual === $case['expected']) {
        fwrite(STDOUT, 'PASS ' . $case['name'] . PHP_EOL);
        continue;
    }

    $failures++;
    fwrite(
        STDERR,
        'FAIL ' . $case['name']
        . ' expected=' . ($case['expected'] ? 'true' : 'false')
        . ' actual=' . ($actual ? 'true' : 'false')
        . PHP_EOL
    );
}

exit($failures === 0 ? 0 : 1);
