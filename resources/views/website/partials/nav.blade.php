<nav id="nav">
    <a class="nav-logo" href="{{ route('landing') }}">
        <div class="logo-box">MG</div>
        <div>
            <div style="font-weight:800;color:var(--ink);font-size:14px;line-height:1.2">{{ $settings->site_name }}</div>
            <div class="fm" style="font-size:9px;color:var(--hint)">{{ $settings->site_domain }}</div>
        </div>
    </a>
    <ul class="nav-links">
        <li><a href="{{ route('landing') }}#about">عن الشركة</a></li>
        <li><a href="{{ route('landing') }}#catalog">المنتجات</a></li>
        <li><a href="{{ route('landing') }}#services">الخدمات</a></li>
        <li><a href="{{ route('landing') }}#points">نظام النقاط</a></li>
        <li><a href="{{ route('contact') }}">التواصل</a></li>
        <li><a href="{{ route('landing') }}#register">انضم</a></li>
    </ul>
    <a href="{{ route('portal') }}" class="nav-cta">دخول النظام</a>
    <div class="burger" onclick="toggleNav()" id="burger">
        <span></span><span></span><span></span>
    </div>
</nav>
<div class="mobile-nav" id="mnav">
    <a href="{{ route('landing') }}#about" onclick="closeNav()">عن الشركة</a>
    <a href="{{ route('landing') }}#catalog" onclick="closeNav()">المنتجات والكتالوج</a>
    <a href="{{ route('landing') }}#services" onclick="closeNav()">الخدمات</a>
    <a href="{{ route('landing') }}#points" onclick="closeNav()">نظام النقاط</a>
    <a href="{{ route('contact') }}" onclick="closeNav()">التواصل</a>
    <a href="{{ route('privacy') }}" onclick="closeNav()">سياسة الخصوصية</a>
    <a href="{{ route('policy') }}" onclick="closeNav()">السياسات والشروط</a>
    <a href="{{ route('landing') }}#register" onclick="closeNav()">انضم للشبكة</a>
    <a href="{{ route('portal') }}" style="color:var(--blue);font-weight:700">دخول النظام</a>
</div>
