<?php
/**
 * SnackQuest — accessible, responsive transactional mail templates.
 * Every HTML value is escaped and every message includes a complete text fallback.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Auth;

final class MailTemplate
{
    /** @return array{text:string,html:string} */
    public static function verification(string $url, int $hours): array
    {
        $hours = max(1, min(168, $hours));
        $text = "Hallo,\n\nwillkommen bei SnackQuest! Bestätige bitte deine E-Mail-Adresse:\n\n{$url}\n\n"
            . "Der Link ist {$hours} Stunden gültig. Falls du dich nicht registriert hast, kannst du diese E-Mail ignorieren.\n\n"
            . "SnackQuest · julian-neumann.org";
        $html = self::layout(
            'E-Mail-Adresse bestätigen',
            'Nur noch ein Schritt',
            '<p style="margin:0 0 18px;color:#c8cbd4;font-size:16px;line-height:1.65">Willkommen bei SnackQuest. Bestätige jetzt deine E-Mail-Adresse und starte danach direkt mit deiner persönlichen Auswahl.</p>'
            . self::button('E-Mail-Adresse bestätigen', $url)
            . '<p style="margin:22px 0 0;color:#8f95a3;font-size:14px;line-height:1.55">Der Link ist ' . $hours . ' Stunden gültig. Falls du dich nicht registriert hast, kannst du diese E-Mail einfach ignorieren.</p>',
            $url
        );
        return ['text' => $text, 'html' => $html];
    }

    /** @return array{text:string,html:string} */
    public static function welcome(string $displayName, string $appUrl): array
    {
        $name = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($displayName));
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        $name = $name !== '' ? mb_substr($name, 0, 80) : 'Snack-Fan';
        $text = "Hallo {$name},\n\ndeine E-Mail-Adresse ist bestätigt. SnackQuest ist bereit für deinen ersten Scan.\n\n"
            . "Jetzt entdecken: {$appUrl}\n\nSnackQuest · julian-neumann.org";
        $html = self::layout(
            'Willkommen bei SnackQuest',
            'Schön, dass du da bist',
            '<p style="margin:0 0 18px;color:#3f3a33;font-size:16px;line-height:1.65">Hallo ' . self::h($name) . ', deine E-Mail-Adresse ist bestätigt. Deine persönliche Snack-Bibliothek wartet auf den ersten Scan.</p>'
            . self::button('SnackQuest öffnen', $appUrl),
            $appUrl
        );
        return ['text' => $text, 'html' => $html];
    }

    /** @return array{text:string,html:string} */
    public static function passwordReset(string $url, int $minutes): array
    {
        $minutes = max(5, min(1440, $minutes));
        $text = "Hallo,\n\nfür dein SnackQuest-Konto wurde ein neues Passwort angefordert.\n\n"
            . "Neues Passwort setzen: {$url}\n\nDer Link ist {$minutes} Minuten gültig. "
            . "Falls du das nicht warst, ignoriere diese E-Mail — dein Passwort bleibt unverändert.\n\n"
            . "SnackQuest · julian-neumann.org";
        $html = self::layout(
            'Passwort zurücksetzen',
            'Neues Passwort anlegen',
            '<p style="margin:0 0 18px;color:#c8cbd4;font-size:16px;line-height:1.65">Für dein SnackQuest-Konto wurde ein neues Passwort angefordert. Über den folgenden Button kannst du ein neues festlegen.</p>'
            . self::button('Neues Passwort setzen', $url)
            . '<p style="margin:22px 0 0;color:#8f95a3;font-size:14px;line-height:1.55">Der Link ist ' . $minutes . ' Minuten gültig. Falls du das nicht warst, ignoriere diese E-Mail. Dein bisheriges Passwort bleibt unverändert.</p>',
            $url
        );
        return ['text' => $text, 'html' => $html];
    }

    private static function layout(string $title, string $eyebrow, string $content, string $fallbackUrl): string
    {
        $safeTitle = self::h($title);
        $safeEyebrow = self::h($eyebrow);
        $safeUrl = self::h($fallbackUrl);
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark"><title>' . $safeTitle . '</title></head>'
            . '<body style="margin:0;padding:0;background:#fffdf5;color:#181816;font-family:Segoe UI,Arial,sans-serif">'
            . '<div style="display:none;max-height:0;overflow:hidden;color:transparent">' . $safeTitle . ' · SnackQuest</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#fffdf5"><tr><td align="center" style="padding:28px 14px">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px">'
            . '<tr><td style="padding:0 4px 18px;color:#ff6b35;font-size:20px;font-weight:900;letter-spacing:.02em">SnackQuest<span style="color:#181816">.</span></td></tr>'
            . '<tr><td style="padding:34px 30px;border:1px solid #e8e2d7;border-radius:20px;background:#ffffff">'
            . '<div style="margin:0 0 10px;color:#b53c6f;font-size:12px;font-weight:750;letter-spacing:.12em;text-transform:uppercase">' . $safeEyebrow . '</div>'
            . '<h1 style="margin:0 0 18px;color:#181816;font-size:28px;line-height:1.2">' . $safeTitle . '</h1>' . $content
            . '<div style="margin-top:28px;padding-top:20px;border-top:1px solid #292d36;color:#747b8a;font-size:12px;line-height:1.55">Button funktioniert nicht? Kopiere diesen Link in deinen Browser:<br>'
            . '<a href="' . $safeUrl . '" style="color:#b9bec9;word-break:break-all">' . $safeUrl . '</a></div>'
            . '</td></tr><tr><td style="padding:18px 4px;color:#68645c;font-size:12px;line-height:1.55">SnackQuest · Dein persönliches Snack-Gedächtnis<br>julian-neumann.org</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function button(string $label, string $url): string
    {
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td style="border-radius:12px;background:#c9f26c">'
            . '<a href="' . self::h($url) . '" style="display:inline-block;padding:14px 21px;color:#17130d;text-decoration:none;font-size:15px;font-weight:800">'
            . self::h($label) . '</a></td></tr></table>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
