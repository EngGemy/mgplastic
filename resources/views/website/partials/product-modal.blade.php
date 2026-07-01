<div class="modal-overlay" id="prod-modal" onclick="closeModal(event)">
    <div class="modal-box">
        <button type="button" class="modal-close" onclick="document.getElementById('prod-modal').classList.remove('open');document.body.style.overflow=''">
            <i class="ti ti-x"></i>
        </button>
        <div class="modal-img" id="modal-img">
            <div id="modal-initials" class="prod-initials" style="font-size:3rem;color:rgba(255,255,255,.9)"></div>
        </div>
        <div class="modal-body">
            <div class="modal-tag fm" id="modal-tag"></div>
            <h2 class="modal-title" id="modal-title"></h2>
            <div class="modal-en fm" id="modal-en"></div>
            <div class="modal-desc" id="modal-desc"></div>
            <div class="modal-usage" id="modal-usage" hidden></div>
            <div class="modal-specs" id="modal-specs"></div>
            <div class="modal-notes" id="modal-notes" hidden></div>
            <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
                <a href="#" id="modal-pdf-btn" class="btn-primary" style="flex:1;min-width:140px;justify-content:center;text-decoration:none" target="_blank">
                    <i class="ti ti-download"></i> PDF المنتج
                </a>
                <a href="#register" class="btn-out" style="flex:1;min-width:140px;justify-content:center;text-decoration:none" onclick="document.getElementById('prod-modal').classList.remove('open');document.body.style.overflow=''">
                    <i class="ti ti-star"></i> اشتر واكسب نقاط
                </a>
            </div>
        </div>
    </div>
</div>
