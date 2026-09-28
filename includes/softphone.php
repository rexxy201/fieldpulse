<?php
// Browser softphone for support agents. Rendered by includes/footer.php only
// when the user has support.view and voice is configured.
?>
<div id="softphone" class="softphone" data-state="idle" aria-live="polite">
  <button type="button" class="softphone-toggle" id="spToggle" aria-expanded="false" aria-controls="spPanel">
    <i class="bi bi-telephone"></i> <span id="spLabel">Phone: connecting…</span>
  </button>
  <div class="softphone-panel" id="spPanel" hidden>
    <div id="spIncoming" hidden>
      <div class="small text-muted">Incoming call</div>
      <div class="fw-bold" id="spInFrom"></div>
      <div class="small" id="spInCustomer"></div>
      <div class="d-flex gap-2 mt-2">
        <button type="button" class="btn btn-sm btn-success flex-grow-1" id="spAnswer"><i class="bi bi-telephone-inbound"></i> Answer</button>
        <button type="button" class="btn btn-sm btn-outline-danger" id="spDecline">Decline</button>
      </div>
    </div>
    <div id="spActive" hidden>
      <div class="d-flex justify-content-between"><span class="fw-bold" id="spWith"></span><span class="font-monospace" id="spTimer">0:00</span></div>
      <div class="small" id="spActiveCustomer"></div>
      <div class="d-flex flex-wrap gap-1 mt-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="spMute">Mute</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="spHold">Hold</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="spKeys">Keypad</button>
        <button type="button" class="btn btn-sm btn-danger ms-auto" id="spHangup"><i class="bi bi-telephone-x"></i> End</button>
      </div>
      <div id="spKeypad" class="softphone-keypad mt-2" hidden>
        <?php foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $k): ?><button type="button" class="btn btn-sm btn-light border" data-dtmf="<?= $k ?>"><?= $k ?></button><?php endforeach; ?>
      </div>
      <div class="small text-muted mt-2">Keep this tab open until the call ends; open customer pages in a new tab.</div>
    </div>
    <form id="spDial" class="d-flex gap-1">
      <input type="tel" class="form-control form-control-sm" id="spNumber" placeholder="Number to call" autocomplete="off">
      <button class="btn btn-sm btn-primary" id="spCall"><i class="bi bi-telephone-outbound"></i></button>
    </form>
    <div class="small text-danger mt-1" id="spError" hidden></div>
  </div>
</div>
<script src="<?= assetUrl('/assets/lib/africastalking-client-1.0.8.js') ?>"></script>
<script src="<?= assetUrl('/assets/softphone.js') ?>"></script>
