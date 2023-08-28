<?php

/**
 * dmarc-srg - A php parser, viewer and summary report generator for incoming DMARC reports.
 * Copyright (C) 2020 Aleksey Andreev (liuch)
 *
 * Available at:
 * https://github.com/liuch/dmarc-srg
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of  MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * =========================
 *
 * This script creates a summary report and sends it by email.
 * The email addresses must be specified in the configuration file.
 * The script have two required parameters: `domain` and `period`, and two optional: `emailto` and `format`.
 * The `domain` parameter must contain a domain name, a comma-separated list of domains, or `all`.
 * The `period` parameter must have one of these values:
 *   `lastmonth`   - to make a report for the last month;
 *   `lastweek`    - to make a report for the last week;
 *   `lastndays:N` - to make a report for the last N days;
 * The `emailto` parameter is optional. Set it if you want to use a different email address to sent the report to.
 * The `format` parameter is optional. It provides the ability to specify the email message format.
 * Possible values are: `text`, `html`, `text+html`. The default value is `text`.
 *
 * Some examples:
 *
 * $ php utils/summary_report.php domain=example.com period=lastweek
 * will send a weekly summary report by email for the domain example.com
 *
 * $ php utils/summary_report.php domain=example.com period=lastndays:10
 * will send a summary report by email for last 10 days for the domain example.com
 *
 * The best place to use it is cron.
 * Note: the current directory must be the one containing the classes directory.
 *
 * @category Utilities
 * @package  DmarcSrg
 * @author   Aleksey Andreev (liuch)
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU/GPLv3
 */

namespace Liuch\DmarcSrg;

use Liuch\DmarcSrg\Mail\MailBody;
use Liuch\DmarcSrg\Domains\Domain;
use Liuch\DmarcSrg\Domains\DomainList;
use Liuch\DmarcSrg\Report\SummaryReport;
use Liuch\DmarcSrg\Exception\SoftException;
use Liuch\DmarcSrg\Exception\RuntimeException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require 'init.php';

if (php_sapi_name() !== 'cli') {
    echo 'Forbidden' . PHP_EOL;
    exit(1);
}

$domain  = null;
$period  = null;
$emailto = null;
$format  = 'text';
for ($i = 1; $i < count($argv); ++$i) {
    $av = explode('=', $argv[$i]);
    if (count($av) == 2) {
        switch ($av[0]) {
            case 'domain':
                $domain = $av[1];
                break;
            case 'period':
                $period = $av[1];
                break;
            case 'emailto':
                $emailto = $av[1];
                break;
            case 'format':
                $format = $av[1];
                break;
        }
    }
}

$core = Core::instance();
try {
    $core->user('admin');
    if (!$domain) {
        throw new SoftException('Parameter "domain" is not specified');
    }
    if (!$period) {
        throw new SoftException('Parameter "period" is not specified');
    }
    if (!in_array($format, [ 'text', 'html', 'text+html' ], true)) {
        throw new SoftException('Unknown email message format: ' . $format);
    }
    if (!$emailto) {
        $emailto = $core->config('mailer/default');
    }

    if ($domain === 'all') {
        $domains = (new DomainList())->getList()['domains'];
    } else {
        $domains = array_map(function ($d) {
            return new Domain($d);
        }, explode(',', $domain));
    }

    $rep = new SummaryReport($period);
    switch ($format) {
        case 'text':
            $text = [];
            $html = null;
            break;
        case 'html':
            $text = null;
            $html = [];
            break;
        default:
            $text = [];
            $html = [];
            break;
    }
    if (!is_null($html)) {
        $html[] = '<html><body>';
    }
    $dom_cnt = count($domains);
    for ($i = 0; $i < $dom_cnt; ++$i) {
        if ($i > 0) {
            if (!is_null($text)) {
                $text[] = '-----------------------------------';
                $text[] = '';
            }
            if (!is_null($html)) {
                $html[] = '<hr style="margin:2em 0;" />';
            }
        }

        $domain = $domains[$i];
        if ($domain->exists()) {
            $rep->setDomain($domain);
            if (!is_null($text)) {
                foreach ($rep->text() as &$row) {
                    $text[] = $row;
                }
                unset($row);
            }
            if (!is_null($html)) {
                foreach ($rep->html() as &$row) {
                    $html[] = $row;
                }
                unset($row);
            }
        } else {
            $nf_message = "Domain \"{$domain->fqdn()}\" does not exist";
            if ($dom_cnt === 1) {
                throw new SoftException("Domain \"{$domain->fqdn()}\" does not exist");
            }
            if (!is_null($text)) {
                $text[] = "# {$nf_message}";
                $text[] = '';
            }
            if (!is_null($html)) {
                $html[] = '<h2>' . htmlspecialchars($nf_message) . '</h2>';
            }
        }
    }

    if ($dom_cnt === 1) {
        $subject = "{$rep->subject()} for {$domain->fqdn()}";
    } else {
        $subject = "{$rep->subject()} for {$dom_cnt} domains";
    }
    if (!is_null($html)) {
        $html[] = '</body></html>';
    }

    if ($core->config('mailer/method', 'default') === 'smtp') {
        $phpMailerPath = dirname(__DIR__) . '/includes/PHPMailer/';
        require $phpMailerPath . 'Exception.php';
        require $phpMailerPath . 'PHPMailer.php';
        require $phpMailerPath . 'SMTP.php';

        $phpMailer = new PHPMailer();
        $phpMailer->isSMTP();
        if ($core->config('debug', 0)) {
            $phpMailer->SMTPDebug = SMTP::DEBUG_SERVER;
        }
        $phpMailer->SMTPAuth = true;
        $phpMailer->Host = $core->config('mailer/smtp_host');
        $phpMailer->Username = $core->config('mailer/username');
        $phpMailer->Password = $core->config('mailer/password');
        $phpMailer->setFrom($core->config('mailer/from'),
            $core->config('mailer/from_name', ''));
        $phpMailer->addAddress($emailto);
        $phpMailer->Subject = $subject;

        if (!is_null($html)) {
            $phpMailer->isHTML(true);
            $phpMailer->Body = implode("\r\n", $html);
            if (!is_null($text)) {
                $phpMailer->AltBody = implode("\r\n", $text);
            }
        } else {
            $phpMailer->Body = implode("\r\n", $text);
        }
        if (!$phpMailer->send()) {
            echo 'Mailer Error: ' . $phpMailer->ErrorInfo . PHP_EOL;
            exit(1);
        }
    } else {
        $mbody = new MailBody();
        if (!is_null($text)) {
            $mbody->setText($text);
        }
        if (!is_null($html)) {
            $mbody->setHtml($html);
        }

        $headers = [
            'From'         => $core->config('mailer/from'),
            'MIME-Version' => '1.0',
            'Content-Type' => $mbody->contentType()
        ];

        mail(
            $emailto,
            mb_encode_mimeheader($subject, 'UTF-8'),
            implode("\r\n", $mbody->content()),
            $headers
        );

    }
} catch (SoftException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
} catch (RuntimeException $e) {
    echo ErrorHandler::exceptionText($e);
    exit(1);
}

exit(0);
