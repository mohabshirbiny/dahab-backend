<!DOCTYPE html>
<html lang="{{ $arabic ? 'ar' : 'en' }}" dir="{{ $arabic ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $arabic ? 'مرحبًا بك في ' . $appName : 'Welcome to ' . $appName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <tr>
            <td>
                <h1 style="margin:0 0 16px;font-size:20px;">
                    {{ $arabic ? 'مرحبًا بك في ' . $appName : 'Welcome to ' . $appName }}
                </h1>

                <p style="margin:0 0 16px;line-height:1.6;">
                    {{ $arabic
                        ? 'تم إنشاء حسابك بنجاح.'
                        : 'Your account has been created successfully.' }}
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
                    <tr>
                        <td style="padding:4px 12px 4px 0;color:#666;">{{ $arabic ? 'رقم العميل' : 'Customer reference' }}</td>
                        <td style="padding:4px 0;font-weight:bold;">{{ $customer->display_ref }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 12px 4px 0;color:#666;">{{ $arabic ? 'رقم الهاتف' : 'Phone' }}</td>
                        <td style="padding:4px 0;">{{ $customer->phone }}</td>
                    </tr>
                </table>

                <p style="margin:0 0 16px;line-height:1.6;">
                    {{ $arabic
                        ? 'لتتمكن من التداول، يلزم رفع وثيقة هوية ومراجعتها من فريقنا.'
                        : 'Before you can trade, you will need to submit an identity document for review.' }}
                </p>

                <p style="margin:0;color:#888;font-size:12px;line-height:1.6;">
                    {{ $arabic
                        ? 'إذا لم تكن أنت من أنشأ هذا الحساب، يرجى التواصل معنا فورًا.'
                        : 'If you did not create this account, please contact us immediately.' }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
