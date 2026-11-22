@php $mailLocale = (string) ($locale ?? app()->getLocale()); $isRtl = app(\App\Domain\Localization\LanguageService::class)->direction((string) ($locale ?? app()->getLocale())) === \App\Enums\Language\DirectionEnum::RTL->key(); @endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ $locale ?? app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <title>{{ $subject ?? config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <meta name="x-apple-disable-message-reformatting">
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        /* Base Reset */
        body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; background-color: #0b0f19; }
        table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        
        /* Mobile Responsive Breakpoints */
        @media only screen and (max-width: 620px) {
            .container-table { width: 100% !important; border-radius: 0 !important; }
            .content-padding { padding: 28px 20px !important; }
            .feature-stack { display: block !important; width: 100% !important; padding-left: 0 !important; padding-right: 0 !important; margin-bottom: 12px !important; }
            .footer-table { width: 100% !important; padding: 24px 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #0b0f19; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <div role="article" aria-roledescription="email" aria-label="{{ $subject ?? config('app.name') }}" style="background-color: #0b0f19;">
        <!--[if (gte mso 9)|(IE)]>
        <table width="100%" align="center" cellpadding="0" cellspacing="0" border="0" style="background-color: #0b0f19;">
        <tr>
        <td align="center" valign="top">
        <![endif]-->

        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0; padding: 0; width: 100%; background-color: #0b0f19;">
            <tr>
                <td align="center" style="padding: 0;">
                    
                    <!-- TOP HEADER: INSTITUTIONAL BRANDING -->
                    <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                        <tr>
                            <td align="center" style="padding: 36px 20px 24px 20px;">
                                <a href="{{ config('app.url') }}" target="_blank" style="text-decoration: none; display: inline-block;">
                                    <table cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
                                        <tr>
                                            <td align="center" style="padding-bottom: 10px;">
                                                <table cellpadding="0" cellspacing="0" role="presentation">
                                                    <tr>
                                                        <td align="center" style="background: linear-gradient(135deg, #1B669D 0%, #1C9976 100%); border-radius: 12px; padding: 10px 16px; box-shadow: 0 4px 14px rgba(27, 102, 157, 0.4);">
                                                            <span style="font-size: 19px; font-weight: 900; color: #ffffff; letter-spacing: 2px; text-transform: uppercase;">
                                                                ONDA
                                                            </span>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td align="center" style="font-size: 11px; font-weight: 600; color: #94a3b8; letter-spacing: 0.5px; text-transform: uppercase;">
                                                الديوان الوطني لحقوق المؤلف والحقوق المجاورة
                                            </td>
                                        </tr>
                                        <tr>
                                            <td align="center" style="font-size: 10px; font-weight: 500; color: #64748b; letter-spacing: 0.3px;">
                                                Office National des Droits d'Auteur et des Droits Voisins
                                            </td>
                                        </tr>
                                    </table>
                                </a>
                            </td>
                        </tr>
                    </table>

                    <!-- MAIN EMAIL CARD CONTAINER -->
                    <!--[if (gte mso 9)|(IE)]>
                    <table width="600" align="center" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                    <td>
                    <![endif]-->
                    <table class="container-table" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto; width: 600px; max-width: 600px; background-color: #111827; border: 1px solid #1e293b; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); overflow: hidden;">
                        
                        <!-- Top Gradient Accent Strip -->
                        <tr>
                            <td height="4" style="background: linear-gradient(90deg, #1B669D 0%, #1C9976 100%); font-size: 0; line-height: 0; border-top-left-radius: 15px; border-top-right-radius: 15px;">&nbsp;</td>
                        </tr>

                        <!-- Main Content Body -->
                        <tr>
                            <td class="content-padding" style="padding: 40px 40px 36px 40px; text-align: {{ $isRtl ? 'right' : 'left' }};">
                                
                                <!-- Greeting & Title -->
                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                    @if(isset($title))
                                    <tr>
                                        <td style="padding-bottom: 12px;">
                                            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #ffffff; letter-spacing: -0.3px; line-height: 1.3;">
                                                {{ $title }}
                                            </h1>
                                        </td>
                                    </tr>
                                    @endif
                                    
                                    @if(isset($greeting))
                                    <tr>
                                        <td style="padding-bottom: 16px; font-size: 15px; font-weight: 600; color: #f1f5f9; line-height: 1.5;">
                                            {{ $greeting }}
                                        </td>
                                    </tr>
                                    @endif

                                    <!-- Introduction Context Paragraphs -->
                                    @if(isset($introLines) && is_array($introLines))
                                        @foreach($introLines as $line)
                                        <tr>
                                            <td style="padding-bottom: 14px; font-size: 15px; color: #cbd5e1; line-height: 1.6;">
                                                {{ $line }}
                                            </td>
                                        </tr>
                                        @endforeach
                                    @elseif(isset($intro))
                                        <tr>
                                            <td style="padding-bottom: 16px; font-size: 15px; color: #cbd5e1; line-height: 1.6;">
                                                {{ $intro }}
                                            </td>
                                        </tr>
                                    @endif
                                </table>

                                <!-- FEATURE CARDS BLOCK (3 HIGHLIGHTS) -->
                                @if(isset($showFeatures) && $showFeatures)
                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0 28px 0;">
                                    <tr>
                                        <td style="padding-bottom: 12px; font-size: 13px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">
                                            {{ $featuresTitle ?? __('mail.features_title', [], $mailLocale) }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <!-- Feature 1: Legal Protection -->
                                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 10px; background-color: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden;">
                                                <tr>
                                                    <td width="48" align="center" valign="middle" style="padding: 14px 0 14px 16px;">
                                                        <table cellpadding="0" cellspacing="0" role="presentation">
                                                            <tr>
                                                                <td align="center" style="background-color: rgba(27, 102, 157, 0.2); border-radius: 8px; width: 32px; height: 32px; font-size: 16px;">
                                                                    🛡️
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                    <td style="padding: 14px 16px;">
                                                        <div style="font-size: 14px; font-weight: 600; color: #ffffff; margin-bottom: 2px;">
                                                            {{ $feature1Title ?? __('mail.feature1_title', [], $mailLocale) }}
                                                        </div>
                                                        <div style="font-size: 12px; color: #94a3b8; line-height: 1.4;">
                                                            {{ $feature1Desc ?? __('mail.feature1_desc', [], $mailLocale) }}
                                                        </div>
                                                    </td>
                                                </tr>
                                            </table>

                                            <!-- Feature 2: Digital Certificates -->
                                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom: 10px; background-color: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden;">
                                                <tr>
                                                    <td width="48" align="center" valign="middle" style="padding: 14px 0 14px 16px;">
                                                        <table cellpadding="0" cellspacing="0" role="presentation">
                                                            <tr>
                                                                <td align="center" style="background-color: rgba(28, 153, 118, 0.2); border-radius: 8px; width: 32px; height: 32px; font-size: 16px;">
                                                                    📜
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                    <td style="padding: 14px 16px;">
                                                        <div style="font-size: 14px; font-weight: 600; color: #ffffff; margin-bottom: 2px;">
                                                            {{ $feature2Title ?? __('mail.feature2_title', [], $mailLocale) }}
                                                        </div>
                                                        <div style="font-size: 12px; color: #94a3b8; line-height: 1.4;">
                                                            {{ $feature2Desc ?? __('mail.feature2_desc', [], $mailLocale) }}
                                                        </div>
                                                    </td>
                                                </tr>
                                            </table>

                                            <!-- Feature 3: Sovereign Security -->
                                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden;">
                                                <tr>
                                                    <td width="48" align="center" valign="middle" style="padding: 14px 0 14px 16px;">
                                                        <table cellpadding="0" cellspacing="0" role="presentation">
                                                            <tr>
                                                                <td align="center" style="background-color: rgba(27, 102, 157, 0.2); border-radius: 8px; width: 32px; height: 32px; font-size: 16px;">
                                                                    🔒
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                    <td style="padding: 14px 16px;">
                                                        <div style="font-size: 14px; font-weight: 600; color: #ffffff; margin-bottom: 2px;">
                                                            {{ $feature3Title ?? __('mail.feature3_title', [], $mailLocale) }}
                                                        </div>
                                                        <div style="font-size: 12px; color: #94a3b8; line-height: 1.4;">
                                                            {{ $feature3Desc ?? __('mail.feature3_desc', [], $mailLocale) }}
                                                        </div>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                                @endif

                                <!-- PRIMARY ACTION BUTTON (CTA) -->
                                @if(isset($actionUrl))
                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 28px 0 32px 0;">
                                    <tr>
                                        <td align="center">
                                            <table cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
                                                <tr>
                                                    <td align="center" style="border-radius: 12px; background-color: #1d4ed8; box-shadow: 0 4px 14px rgba(29, 78, 216, 0.4);">
                                                        <!--[if mso]>
                                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $actionUrl }}" style="height:48px;v-text-anchor:middle;width:260px;" arcsize="20%" strokecolor="#1d4ed8" fillcolor="#1d4ed8">
                                                        <w:anchorlock/>
                                                        <center style="color:#ffffff;font-family:sans-serif;font-size:15px;font-weight:bold;">{{ $actionText ?? __('mail.action_default', [], $mailLocale) }}</center>
                                                        </v:roundrect>
                                                        <![endif]-->
                                                        <!--[if !mso]><!-->
                                                        <a href="{{ $actionUrl }}" target="_blank" style="background-color: #1d4ed8; border-radius: 12px; color: #ffffff !important; display: inline-block; font-size: 15px; font-weight: 700; letter-spacing: 0.2px; text-align: center; text-decoration: none; -webkit-text-size-adjust: none; padding: 14px 32px;">
                                                            {{ $actionText ?? __('mail.action_default', [], $mailLocale) }}
                                                        </a>
                                                        <!--<![endif]-->
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                                @endif

                                <!-- SAFETY & SECURITY WARNING CALLOUT BLOCK -->
                                @if($showWarning ?? true)
                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 20px 0 24px 0; border-collapse: separate;">
                                    <tr>
                                        <td style="background-color: #1e293b; border: 1px solid #334155; border-left: 4px solid #f97316; border-radius: 0 12px 12px 0; padding: 16px 20px;">
                                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                                <tr>
                                                    <td>
                                                        <div style="font-size: 13px; font-weight: 700; color: #f97316; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">
                                                            ⚠️ {{ $warningTitle ?? __('mail.warning_title', [], $mailLocale) }}
                                                        </div>
                                                        <div style="font-size: 13px; color: #cbd5e1; line-height: 1.5;">
                                                            {{ $warningText ?? __('mail.warning_text', [], $mailLocale) }}
                                                        </div>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                @endif

                                <!-- Outro Lines -->
                                @if(isset($outroLines) && is_array($outroLines))
                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top: 16px;">
                                    @foreach($outroLines as $line)
                                    <tr>
                                        <td style="padding-bottom: 10px; font-size: 14px; color: #94a3b8; line-height: 1.5;">
                                            {{ $line }}
                                        </td>
                                    </tr>
                                    @endforeach
                                </table>
                                @endif

                                <!-- Subcopy Fallback Raw URL -->
                                @if(isset($actionUrl))
                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top: 28px; border-top: 1px solid #1e293b; padding-top: 20px;">
                                    <tr>
                                        <td style="font-size: 12px; color: #64748b; line-height: 1.5; text-align: {{ $isRtl ? 'right' : 'left' }};">
                                            @if(isset($subcopy))
                                            {{ $subcopy }}
                                            @endif
                                            <div dir="ltr" style="margin-top: 6px; word-break: break-all; text-align: left;">
                                                <a href="{{ $actionUrl }}" target="_blank" style="color: #38bdf8; text-decoration: underline;">
                                                    {{ $actionUrl }}
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                                @endif

                            </td>
                        </tr>
                    </table>
                    <!--[if (gte mso 9)|(IE)]>
                    </td>
                    </tr>
                    </table>
                    <![endif]-->

                    <!-- SOVEREIGN FOOTER -->
                    <table class="footer-table" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto; width: 600px; max-width: 600px; padding: 28px 20px 40px 20px; text-align: center;">
                        <tr>
                            <td align="center" style="font-size: 11px; color: #64748b; line-height: 1.6; text-align: center;">
                                <p style="margin: 0 0 6px 0; font-size: 11px; color: #94a3b8; font-weight: 600; letter-spacing: 0.2px;">
                                    {{ __('mail.republic', [], $mailLocale) }}
                                </p>
                                <p style="margin: 0 0 8px 0; font-size: 11px; color: #64748b;">
                                    {{ __('mail.office_name', [], $mailLocale) }} (ONDA) — <span dir="ltr" style="unicode-bidi: isolate;">{{ __('mail.address', [], $mailLocale) }}</span>
                                </p>
                                <p style="margin: 0; font-size: 10px; color: #475569;">
                                    {{ __('mail.copyright', ['year' => date('Y')], $mailLocale) }}
                                </p>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>

        <!--[if (gte mso 9)|(IE)]>
        </td>
        </tr>
        </table>
        <![endif]-->
    </div>
</body>
</html>
