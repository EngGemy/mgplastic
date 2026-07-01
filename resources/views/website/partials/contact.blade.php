@php
    $phone = $settings->contact_phone;
    $whatsapp = $settings->contact_whatsapp;
    $waLink = $whatsapp ? 'https://wa.me/'.preg_replace('/\D+/', '', $whatsapp) : null;
@endphp

<section id="contact" class="contact-bg" style="padding:80px 5vw">
    <div class="sec-inner">
        <div style="text-align:center;margin-bottom:40px" class="reveal">
            <span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.18em;color:rgba(255,255,255,.4);text-transform:uppercase;margin-bottom:10px;display:block">Contact Us</span>
            <h2 style="font-family:'Amiri',serif;font-size:clamp(1.8rem,3.5vw,2.8rem);color:#fff;margin-bottom:6px">تواصل معنا</h2>
            <div class="fm" style="font-size:11px;color:rgba(255,255,255,.3);letter-spacing:.06em">// we_are_in.tripoli_libya</div>
        </div>

        <div class="contact-grid reveal">
            <div style="padding:40px 36px;background:rgba(255,255,255,.04)">
                <h3 style="font-family:'Amiri',serif;font-size:1.8rem;color:#fff;margin-bottom:4px">بيانات التواصل</h3>
                <div class="fm" style="font-size:9px;color:rgba(255,255,255,.3);letter-spacing:.12em;text-transform:uppercase;margin-bottom:28px">// reach_us.anytime</div>

                <div style="display:flex;flex-direction:column;gap:20px">
                    @if($phone)
                        <div style="display:flex;align-items:flex-start;gap:14px">
                            <div class="ci-icon"><i class="ti ti-phone"></i></div>
                            <div>
                                <div class="fm" style="font-size:9px;color:rgba(255,255,255,.4);letter-spacing:.1em;text-transform:uppercase;margin-bottom:3px">الهاتف الرئيسي</div>
                                <a href="tel:{{ $phone }}" dir="ltr" style="color:#fff;font-size:15px;font-weight:700;text-decoration:none">{{ $phone }}</a>
                            </div>
                        </div>
                    @endif

                    @if($whatsapp)
                        <div style="display:flex;align-items:flex-start;gap:14px">
                            <div class="ci-icon" style="background:#25D366aa">
                                <i class="ti ti-brand-whatsapp" style="color:#25D366"></i>
                            </div>
                            <div>
                                <div class="fm" style="font-size:9px;color:rgba(255,255,255,.4);letter-spacing:.1em;text-transform:uppercase;margin-bottom:3px">واتساب</div>
                                <a href="{{ $waLink }}" target="_blank" style="color:#25D366;font-size:15px;font-weight:700;text-decoration:none" dir="ltr">{{ $whatsapp }}</a>
                            </div>
                        </div>
                    @endif

                    @if($settings->contact_email)
                        <div style="display:flex;align-items:flex-start;gap:14px">
                            <div class="ci-icon"><i class="ti ti-mail"></i></div>
                            <div>
                                <div class="fm" style="font-size:9px;color:rgba(255,255,255,.4);letter-spacing:.1em;text-transform:uppercase;margin-bottom:3px">البريد الإلكتروني</div>
                                <a href="mailto:{{ $settings->contact_email }}" style="color:#60a5fa;font-size:14px;font-weight:600;text-decoration:none">{{ $settings->contact_email }}</a>
                            </div>
                        </div>
                    @endif

                    @if($settings->contact_address)
                        <div style="display:flex;align-items:flex-start;gap:14px">
                            <div class="ci-icon"><i class="ti ti-map-pin"></i></div>
                            <div>
                                <div class="fm" style="font-size:9px;color:rgba(255,255,255,.4);letter-spacing:.1em;text-transform:uppercase;margin-bottom:3px">العنوان</div>
                                <div style="color:#fff;font-size:14px;font-weight:700">{{ $settings->contact_address }}</div>
                                @if($settings->contact_address_detail)
                                    <div style="color:rgba(255,255,255,.5);font-size:12px;margin-top:2px">{{ $settings->contact_address_detail }}</div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($settings->contact_work_days)
                        <div style="display:flex;align-items:flex-start;gap:14px">
                            <div class="ci-icon"><i class="ti ti-clock"></i></div>
                            <div>
                                <div class="fm" style="font-size:9px;color:rgba(255,255,255,.4);letter-spacing:.1em;text-transform:uppercase;margin-bottom:3px">ساعات العمل</div>
                                <div style="color:#fff;font-size:14px;font-weight:700">{{ $settings->contact_work_days }}</div>
                                @if($settings->contact_work_hours)
                                    <div style="color:rgba(255,255,255,.5);font-size:12px;margin-top:2px">{{ $settings->contact_work_hours }}</div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($waLink)
                        <a href="{{ $waLink }}" target="_blank"
                           style="display:flex;align-items:center;gap:10px;background:#25D366;color:#fff;padding:12px 18px;border-radius:12px;text-decoration:none;font-weight:700;font-size:14px;margin-top:8px">
                            <i class="ti ti-brand-whatsapp" style="font-size:20px"></i>
                            راسلنا على واتساب الآن
                        </a>
                    @endif
                </div>
            </div>
            <div style="min-height:420px;position:relative">
                <div id="map" style="height:100%;min-height:420px"></div>
            </div>
        </div>
    </div>
</section>
