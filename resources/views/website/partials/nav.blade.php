<nav id="nav">
    <a class="nav-logo" href="{{ route('landing') }}">
        <div class="logo-box">MG</div>
        <div>
            <div style="font-weight:800;color:var(--ink);font-size:14px;line-height:1.2">{{ $settings->site_name }}</div>
            <div class="fm" style="font-size:9px;color:var(--hint)">{{ $settings->site_domain }}</div>
        </div>
    </a>
    <ul class="nav-links">
        <li><a href="#about">عن الشركة</a></li>
        <li><a href="#catalog">المنتجات</a></li>
        <li><a href="#services">الخدمات</a></li>
        <li><a href="#points">نظام النقاط</a></li>
        <li><a href="#contact">التواصل</a></li>
        <li><a href="#register">انضم</a></li>
    </ul>
    <a href="{{ route('portal') }}" class="nav-cta">دخول النظام</a>
    <div class="burger" onclick="toggleNav()" id="burger">
        <span></span><span></span><span></span>
    </div>
</nav>
<div class="mobile-nav" id="mnav">
    <a href="#about" onclick="closeNav()">عن الشركة</a>
    <a href="#catalog" onclick="closeNav()">المنتجات والكتالوج</a>
    <a href="#services" onclick="closeNav()">الخدمات</a>
    <a href="#points" onclick="closeNav()">نظام النقاط</a>
    <a href="#contact" onclick="closeNav()">التواصل</a>
    <a href="#register" onclick="closeNav()">انضم للشبكة</a>
    <a href="{{ route('portal') }}" style="color:var(--blue);font-weight:700">دخول النظام</a>
</div>
