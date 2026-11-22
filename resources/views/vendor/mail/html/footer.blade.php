<tr>
<td align="center" style="padding: 0;">
<table class="footer" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto; width: 600px; max-width: 600px; padding: 28px 20px 40px 20px; text-align: center;">
<tr>
<td align="center" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 11px; color: #64748b; line-height: 1.6; text-align: center;">
<!-- Sovereign Algerian Credentials -->
<p style="margin: 0 0 6px 0; font-size: 11px; color: #94a3b8; font-weight: 600; letter-spacing: 0.2px;">
{{ __('mail.republic') }}
</p>
<p style="margin: 0 0 10px 0; font-size: 11px; color: #64748b;">
{{ __('mail.office_name') }} (ONDA) — <span dir="ltr" style="unicode-bidi: isolate;">{{ __('mail.address') }}</span>
</p>

<!-- Legal & Privacy Notice -->
<p style="margin: 0 0 12px 0; font-size: 10px; color: #475569; line-height: 1.5; max-width: 480px;">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</p>

<p style="margin: 0; font-size: 10px; color: #475569;">
{{ __('mail.copyright', ['year' => date('Y')]) }}
</p>
</td>
</tr>
</table>
</td>
</tr>
