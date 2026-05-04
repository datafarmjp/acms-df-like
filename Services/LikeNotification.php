<?php

namespace Acms\Plugins\DF_Like\Services;

use Acms\Services\Facades\Common;
use Acms\Services\Facades\Mailer;
use Field;

class LikeNotification
{
    public static function sendOnLike(array $target): array
    {
        $blogId = (int)($target['blog_id'] ?? 0);
        if (self::configValue('df_like_notify_enabled', 'disabled', $blogId) !== 'enabled') {
            return ['notification_status' => 'disabled'];
        }
        $formId = (int)self::configValue('df_like_notify_form_id', '0', $blogId);
        if ($formId <= 0) {
            return ['notification_status' => 'failure', 'notification_message' => '通知フォームが指定されていません。'];
        }

        try {
            $form = self::formById($formId);
            if (!$form) {
                return ['notification_status' => 'failure', 'notification_message' => '通知フォームが見つかりません。'];
            }
            $mail = $form['data']->getChild('mail');
            $fields = self::mailFields($target);
            $to = $mail->getArray('AdminTo');
            $subject = self::templateText($mail, $fields, 'AdminSubject', 'AdminSubjectTpl');
            $body = self::templateText($mail, $fields, 'AdminBody', 'AdminBodyTpl');
            if (!$to || $subject === '' || $body === '') {
                return ['notification_status' => 'failure', 'notification_message' => '通知フォームの宛先・件名・本文を確認してください。'];
            }

            $from = $mail->get('AdminFrom') ?: $mail->get('To');
            $replyTo = $mail->getArray('AdminReply-To') ?: $mail->getArray('To');
            $mailer = Mailer::init();
            $mailer->setFrom($from)
                ->setTo(implode(', ', $to))
                ->setSubject($subject)
                ->setCc(implode(', ', $mail->getArray('AdminCc')))
                ->setBcc(implode(', ', $mail->getArray('AdminBcc')))
                ->setReplyTo(implode(', ', $replyTo));

            $htmlTpl = $mail->get('AdminBodyHTMLTpl');
            if ($htmlTpl && ($path = findTemplate($htmlTpl))) {
                $mailer->setHtml(Common::getMailTxt($path, $fields), $body);
            } else {
                $mailer->setBody($body);
            }
            if ($mail->get('AdminFormSend') !== 'no') {
                $mailer->send();
            }
            return ['notification_status' => 'success'];
        } catch (\Throwable $e) {
            return ['notification_status' => 'failure', 'notification_message' => $e->getMessage()];
        }
    }

    public static function formOptions(int $blogId): array
    {
        $blogId = $blogId > 0 ? $blogId : (defined('BID') ? (int)BID : 0);
        $left = class_exists('\ACMS_RAM') && method_exists('\ACMS_RAM', 'blogLeft') ? (int)\ACMS_RAM::blogLeft($blogId) : 0;
        $right = class_exists('\ACMS_RAM') && method_exists('\ACMS_RAM', 'blogRight') ? (int)\ACMS_RAM::blogRight($blogId) : 0;
        $where = 'form.form_blog_id = :bid';
        $params = ['bid' => $blogId];
        if ($left > 0 && $right > 0) {
            $where = '(form.form_blog_id = :bid OR (form.form_scope = :global AND blog.blog_left <= :left AND blog.blog_right >= :right))';
            $params += ['global' => 'global', 'left' => $left, 'right' => $right];
        }
        $rows = \DB::query([
            'sql' => 'SELECT form.form_id, form.form_code, form.form_name, form.form_blog_id, form.form_scope
                FROM `' . LikeRepository::table('form') . '` AS form
                LEFT JOIN `' . LikeRepository::table('blog') . '` AS blog ON blog.blog_id = form.form_blog_id
                WHERE ' . $where . '
                ORDER BY form.form_id ASC',
            'params' => $params,
        ], 'all') ?: [];

        return array_map(function ($row) {
            return [
                'id' => (int)($row['form_id'] ?? 0),
                'code' => (string)($row['form_code'] ?? ''),
                'name' => (string)($row['form_name'] ?? ''),
                'blog_id' => (int)($row['form_blog_id'] ?? 0),
                'scope' => (string)($row['form_scope'] ?? ''),
            ];
        }, $rows);
    }

    private static function formById(int $formId): ?array
    {
        $row = \DB::query([
            'sql' => 'SELECT form_id, form_blog_id, form_code, form_name, form_scope, form_log, form_data
                FROM `' . LikeRepository::table('form') . '`
                WHERE form_id = :id
                LIMIT 1',
            'params' => ['id' => $formId],
        ], 'row') ?: [];
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['form_id'],
            'bid' => (int)$row['form_blog_id'],
            'code' => (string)$row['form_code'],
            'name' => (string)$row['form_name'],
            'scope' => (string)$row['form_scope'],
            'log' => (string)$row['form_log'],
            'data' => acmsDangerUnserialize($row['form_data']),
        ];
    }

    private static function mailFields(array $target): Field
    {
        $field = new Field();
        $entryId = (int)($target['entry_id'] ?? 0);
        $blogId = (int)($target['blog_id'] ?? 0);
        $field->set('entry_id', $entryId);
        $field->set('entry_title', $entryId > 0 && class_exists('\ACMS_RAM') ? (string)\ACMS_RAM::entryTitle($entryId) : '');
        $field->set('entry_url', $entryId > 0 && function_exists('acmsLink') ? acmsLink(['bid' => $blogId, 'eid' => $entryId]) : '');
        $field->set('object_type', (string)($target['object_type'] ?? 'entry'));
        $field->set('object_id', (string)($target['object_id'] ?? ''));
        $field->set('like_count', (int)($target['count'] ?? 0));
        $field->set('liked_at', date('Y-m-d H:i:s'));
        $field->set('blog_id', $blogId);
        $field->set('referer', (string)($target['referer'] ?? ''));
        return $field;
    }

    private static function templateText(Field $mail, Field $field, string $textKey, string $pathKey): string
    {
        $text = $mail->get($textKey, false);
        if ($text !== false && $text !== '') {
            return Common::getMailTxtFromTxt($text, $field);
        }
        $tpl = $mail->get($pathKey);
        if ($tpl && ($path = findTemplate($tpl))) {
            return Common::getMailTxt($path, $field);
        }
        return '';
    }

    private static function configValue(string $key, string $fallback, int $blogId): string
    {
        return LikeBlogContext::configValue($key, $fallback, $blogId);
    }
}
