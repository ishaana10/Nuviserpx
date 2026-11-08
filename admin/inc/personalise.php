<?php
// "Personalise" panel: theme, accent colour, side menu style, density, text size. Saved per user via classes/UiPrefs.php.
if (!isset($__ui)) return;
$__acc = \App\Services\UiPrefsService::ACCENTS;
?>
<div class="modal fade nv-pers-modal" id="nvPersonalise" tabindex="-1" role="dialog" aria-labelledby="nvPersTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="nvPersTitle"><i class="fas fa-palette mr-2"></i>Personalise</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="nv-pers-sec">
          <span class="nv-pers-title">Appearance</span>
          <div class="nv-seg" role="radiogroup" aria-label="Appearance">
            <label><input type="radio" name="nvp_mode" data-pref="mode" value="light"><span><i class="fas fa-sun"></i> Light</span></label>
            <label><input type="radio" name="nvp_mode" data-pref="mode" value="dark"><span><i class="fas fa-moon"></i> Dark</span></label>
            <label><input type="radio" name="nvp_mode" data-pref="mode" value="auto"><span><i class="fas fa-adjust"></i> Auto</span></label>
          </div>
          <small class="text-muted d-block mt-1">Auto follows your device's light or dark setting.</small>
        </div>

        <div class="nv-pers-sec">
          <span class="nv-pers-title">Accent colour</span>
          <div class="nv-swatches" id="nvSwatches">
            <?php foreach ($__acc as $name => $hex): ?>
              <button type="button" class="nv-swatch" data-accent="<?php echo $hex ?>" style="background:<?php echo $hex ?>" title="<?php echo ucfirst($name) ?>" aria-label="<?php echo ucfirst($name) ?>"></button>
            <?php endforeach; ?>
            <span class="nv-swatch-custom" title="Pick your own colour"><input type="color" id="nvAccentCustom" aria-label="Custom accent colour" value="#2563eb"></span>
          </div>
        </div>

        <div class="nv-pers-sec">
          <span class="nv-pers-title">Side menu</span>
          <div class="nv-seg" role="radiogroup" aria-label="Side menu style">
            <label><input type="radio" name="nvp_sidebar" data-pref="sidebar" value="dark"><span>Dark</span></label>
            <label><input type="radio" name="nvp_sidebar" data-pref="sidebar" value="light"><span>Light</span></label>
            <label><input type="radio" name="nvp_sidebar" data-pref="sidebar" value="accent"><span>Accent</span></label>
          </div>
          <div class="custom-control custom-switch mt-2">
            <input type="checkbox" class="custom-control-input" id="nvpCollapsed" data-pref="collapsed">
            <label class="custom-control-label" for="nvpCollapsed">Start with the menu collapsed to icons</label>
          </div>
        </div>

        <div class="nv-pers-sec">
          <span class="nv-pers-title">Density</span>
          <div class="nv-seg" role="radiogroup" aria-label="Density">
            <label><input type="radio" name="nvp_density" data-pref="density" value="compact"><span>Compact</span></label>
            <label><input type="radio" name="nvp_density" data-pref="density" value="comfortable"><span>Comfortable</span></label>
            <label><input type="radio" name="nvp_density" data-pref="density" value="spacious"><span>Spacious</span></label>
          </div>
        </div>

        <div class="nv-pers-sec">
          <span class="nv-pers-title">Text size</span>
          <div class="nv-seg" role="radiogroup" aria-label="Text size">
            <label><input type="radio" name="nvp_text" data-pref="text" value="small"><span style="font-size:.8rem">Small</span></label>
            <label><input type="radio" name="nvp_text" data-pref="text" value="medium"><span>Medium</span></label>
            <label><input type="radio" name="nvp_text" data-pref="text" value="large"><span style="font-size:1.05rem">Large</span></label>
          </div>
        </div>

        <div class="nv-pers-sec mb-0">
          <span class="nv-pers-title">Pinned menu items</span>
          <ul class="nv-pers-pins" id="nvPersPins"></ul>
          <small class="text-muted d-block mt-1" id="nvPersPinHint">Hover a menu item (or tap the star on a phone) to pin it to the top of the menu. Up to <?php echo \App\Services\UiPrefsService::MAX_PINS ?>.</small>
        </div>
      </div>
      <div class="modal-footer nv-pers-foot">
        <button type="button" class="btn btn-default btn-sm" id="nvPersReset"><i class="fas fa-undo mr-1"></i>Reset to defaults</button>
        <span class="nv-pers-saved" id="nvPersSaved" role="status" aria-live="polite">Saved to your account</span>
        <button type="button" class="btn btn-primary btn-sm" data-dismiss="modal">Done</button>
      </div>
    </div>
  </div>
</div>
