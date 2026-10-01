<link rel="stylesheet" href="{$mfa_css|escape:'html':'UTF-8'}">

<div id="mfa-root" class="mfa" data-config="{$mfa_config|escape:'html':'UTF-8'}">
  <div class="mfa-tabs" role="tablist">
    <button type="button" class="mfa-tab is-active" data-tab="list">{$mfa_tr.tab_list|escape:'html':'UTF-8'}</button>
    <button type="button" class="mfa-tab" data-tab="config">{$mfa_tr.tab_config|escape:'html':'UTF-8'}</button>
    <button type="button" class="mfa-tab" data-tab="info">{$mfa_tr.tab_info|escape:'html':'UTF-8'}</button>
  </div>

  <div id="mfa-toast" class="mfa-toast" style="display:none"></div>

  {* ------------------------------ LIST ------------------------------ *}
  <div class="mfa-pane is-active" data-pane="list">
    {if count($mfa_rows) == 0}
      <p class="mfa-empty">{$mfa_tr.no_manufacturers|escape:'html':'UTF-8'}</p>
    {else}
      <table class="mfa-table" id="mfa-table">
        <thead>
          <tr>
            <th class="mfa-c-switch">{$mfa_tr.col_active|escape:'html':'UTF-8'}</th>
            <th class="mfa-c-id mfa-sortable" data-sort="id">{$mfa_tr.col_id|escape:'html':'UTF-8'}<span class="mfa-sort-ind"></span></th>
            <th class="mfa-c-logo">{$mfa_tr.col_logo|escape:'html':'UTF-8'}</th>
            <th class="mfa-c-name mfa-sortable" data-sort="name">{$mfa_tr.col_name|escape:'html':'UTF-8'}<span class="mfa-sort-ind"></span></th>
            <th class="mfa-c-oos">{$mfa_tr.col_oos|escape:'html':'UTF-8'}</th>
            <th class="mfa-c-label">{$mfa_tr.col_label|escape:'html':'UTF-8'}</th>
            <th class="mfa-c-exc">{$mfa_tr.col_exc|escape:'html':'UTF-8'}</th>
            <th class="mfa-c-save"></th>
          </tr>
        </thead>
        <tbody>
        {foreach from=$mfa_rows item=row}
          <tr class="mfa-row{if $row.products == 0} mfa-row-empty{/if}{if !$row.active} is-off{/if}"
              data-id="{$row.id_manufacturer|intval}"
              data-name="{$row.name|escape:'html':'UTF-8'}"
              data-total="{$row.products|intval}"
              data-exceptions="{$row.exceptions|intval}">
            <td class="mfa-c-switch">
              <label class="mfa-switch" title="{$mfa_tr.switch_title|escape:'html':'UTF-8'}">
                <input type="checkbox" class="mfa-active"{if $row.active} checked="checked"{/if}>
                <span class="mfa-slider"></span>
              </label>
            </td>
            <td class="mfa-c-id">{$row.id_manufacturer|intval}</td>
            <td class="mfa-c-logo">
              {if $row.logo}
                <img src="{$row.logo|escape:'html':'UTF-8'}" alt="">
              {else}
                <span class="mfa-nologo" title="{$mfa_tr.no_logo|escape:'html':'UTF-8'}"></span>
              {/if}
            </td>
            <td class="mfa-c-name">
              <strong>{$row.name|escape:'html':'UTF-8'}</strong>
              <small>{$row.products_label|escape:'html':'UTF-8'}</small>
            </td>
            <td class="mfa-c-oos">
              <select class="mfa-oos">
                <option value="3"{if $row.out_of_stock == 3} selected="selected"{/if}>{$mfa_tr.keep|escape:'html':'UTF-8'}</option>
                <option value="0"{if $row.out_of_stock == 0} selected="selected"{/if}>{$mfa_tr.deny|escape:'html':'UTF-8'}</option>
                <option value="1"{if $row.out_of_stock == 1} selected="selected"{/if}>{$mfa_tr.allow|escape:'html':'UTF-8'}</option>
                <option value="2"{if $row.out_of_stock == 2} selected="selected"{/if}>{$mfa_tr.default|escape:'html':'UTF-8'}</option>
              </select>
            </td>
            <td class="mfa-c-label">
              <div class="mfa-label-top">
                <select class="mfa-preset">
                  <option value="">{$mfa_tr.preset|escape:'html':'UTF-8'}</option>
                </select>
                {if count($mfa_langs) > 1}
                  <select class="mfa-lang" title="{$mfa_tr.lang|escape:'html':'UTF-8'}">
                    {foreach from=$mfa_langs item=lang}
                      <option value="{$lang.id_lang|intval}">{$lang.iso_code|escape:'html':'UTF-8'}</option>
                    {/foreach}
                  </select>
                {/if}
              </div>
              <div class="mfa-label-field">
                {foreach from=$mfa_langs item=lang name=mfalangs}
                  <input type="text" class="mfa-label" maxlength="255"
                         data-lang="{$lang.id_lang|intval}" data-iso="{$lang.iso_code|lower|escape:'html':'UTF-8'}"
                         value="{$row.labels[$lang.id_lang]|escape:'html':'UTF-8'}"
                         {if !$smarty.foreach.mfalangs.first}style="display:none"{/if}>
                {/foreach}
                <button type="button" class="mfa-add-preset" style="display:none" title="{$mfa_tr.plus_title|escape:'html':'UTF-8'}">
                  <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" fill="none"/></svg>
                </button>
              </div>
            </td>
            <td class="mfa-c-exc"></td>
            <td class="mfa-c-save">
              <button type="button" class="mfa-btn mfa-btn-primary mfa-save">{$mfa_tr.save|escape:'html':'UTF-8'}</button>
            </td>
          </tr>
        {/foreach}
        </tbody>
      </table>

      <div class="mfa-bar" id="mfa-bar">
        <div class="mfa-bar-legend"><span class="mfa-legend-dot"></span>{$mfa_tr.legend|escape:'html':'UTF-8'}</div>

        <div class="mfa-bar-group" role="group" aria-label="{$mfa_tr.bar_filter|escape:'html':'UTF-8'}">
          <span class="mfa-bar-title">{$mfa_tr.bar_filter|escape:'html':'UTF-8'}</span>
          <button type="button" class="mfa-fbtn" data-filter="active">{$mfa_tr.f_active|escape:'html':'UTF-8'}</button>
          <button type="button" class="mfa-fbtn" data-filter="inactive">{$mfa_tr.f_inactive|escape:'html':'UTF-8'}</button>
          <button type="button" class="mfa-fbtn" data-filter="exceptions">{$mfa_tr.f_exc|escape:'html':'UTF-8'}</button>
          <button type="button" class="mfa-fbtn is-active" data-filter="all">{$mfa_tr.f_all|escape:'html':'UTF-8'}</button>
        </div>

        <div class="mfa-bar-group" role="group" aria-label="{$mfa_tr.bar_labels|escape:'html':'UTF-8'}">
          <span class="mfa-bar-title">{$mfa_tr.bar_labels|escape:'html':'UTF-8'}</span>
          <button type="button" class="mfa-ibtn" id="mfa-add-label" title="{$mfa_tr.add_label|escape:'html':'UTF-8'}">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
            <span class="mfa-bar-text">{$mfa_tr.add_label|escape:'html':'UTF-8'}</span>
          </button>
          <button type="button" class="mfa-ibtn" id="mfa-del-label" title="{$mfa_tr.remove_label|escape:'html':'UTF-8'}">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3M8 10v8M12 10v8M16 10v8M6 7l1 14h10l1-14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mfa-bar-text">{$mfa_tr.remove_label|escape:'html':'UTF-8'}</span>
          </button>
        </div>

        <div class="mfa-bar-group mfa-bar-save">
          <button type="button" class="mfa-btn mfa-btn-primary" id="mfa-save-all">{$mfa_tr.save_all|escape:'html':'UTF-8'}</button>
        </div>
      </div>
    {/if}
  </div>

  {* ------------------------------ CONFIGURATION ------------------------------ *}
  <div class="mfa-pane" data-pane="config">
    <div class="mfa-card">
      <h3>{$mfa_tr.cfg_title|escape:'html':'UTF-8'}</h3>
      <ul class="mfa-opts">
        <li>
          <label class="mfa-switch"><input type="checkbox" data-setting="show_id"{if $mfa_settings.show_id} checked="checked"{/if}><span class="mfa-slider"></span></label>
          <span>{$mfa_tr.cfg_id|escape:'html':'UTF-8'}</span>
        </li>
        <li>
          <label class="mfa-switch"><input type="checkbox" data-setting="show_logo"{if $mfa_settings.show_logo} checked="checked"{/if}><span class="mfa-slider"></span></label>
          <span>{$mfa_tr.cfg_logo|escape:'html':'UTF-8'}</span>
        </li>
        <li>
          <label class="mfa-switch"><input type="checkbox" data-setting="show_name"{if $mfa_settings.show_name} checked="checked"{/if}><span class="mfa-slider"></span></label>
          <span>{$mfa_tr.cfg_name|escape:'html':'UTF-8'}</span>
        </li>
        <li>
          <label class="mfa-switch"><input type="checkbox" data-setting="show_exc_text"{if $mfa_settings.show_exc_text} checked="checked"{/if}><span class="mfa-slider"></span></label>
          <span>{$mfa_tr.cfg_exc_text|escape:'html':'UTF-8'}</span>
        </li>
        <li>
          <label class="mfa-switch"><input type="checkbox" data-setting="show_label_text"{if $mfa_settings.show_label_text} checked="checked"{/if}><span class="mfa-slider"></span></label>
          <span>{$mfa_tr.cfg_label_text|escape:'html':'UTF-8'}</span>
        </li>
      </ul>
      <p><button type="button" class="mfa-btn mfa-btn-primary" id="mfa-save-settings">{$mfa_tr.save|escape:'html':'UTF-8'}</button></p>
    </div>

    <div class="mfa-card">
      <div class="mfa-config-head">
        <div>
          <h3>{$mfa_tr.cfg_labels_title|escape:'html':'UTF-8'}</h3>
          <p class="mfa-config-intro">{$mfa_tr.cfg_labels_intro|escape:'html':'UTF-8'}</p>
        </div>
        <button type="button" class="mfa-btn mfa-btn-primary" id="mfa-config-add-label">{$mfa_tr.cfg_label_add|escape:'html':'UTF-8'}</button>
      </div>
      <div id="mfa-preset-manager" class="mfa-preset-manager"></div>
    </div>
  </div>

  {* ------------------------------ INFO ------------------------------ *}
  <div class="mfa-pane" data-pane="info">
    <div class="mfa-card">
      <h3>{$mfa_tr.info_title|escape:'html':'UTF-8'}</h3>
      <p>{$mfa_tr.info_intro|escape:'html':'UTF-8'}</p>
      <ul>
        {foreach from=$mfa_tr.info_bullets item=bullet}
          <li>{$bullet|escape:'html':'UTF-8'}</li>
        {/foreach}
      </ul>
      <h4 class="mfa-info-subtitle">{$mfa_tr.info_version_title|escape:'html':'UTF-8'}</h4>
      <p>{$mfa_tr.vs_text|escape:'html':'UTF-8'}</p>
    </div>

    <div class="mfa-card">
      <h3>{$mfa_tr.compat|escape:'html':'UTF-8'}</h3>
      <table class="mfa-info-table">
        <tr>
          <th>{$mfa_tr.php_supported|escape:'html':'UTF-8'}</th>
          <td>PHP {$mfa_php_range|escape:'html':'UTF-8'}</td>
        </tr>
        <tr>
          <th>{$mfa_tr.ps_supported|escape:'html':'UTF-8'}</th>
          <td>PrestaShop {$mfa_ps_range|escape:'html':'UTF-8'}</td>
        </tr>
        <tr>
          <th>{$mfa_tr.php_detected|escape:'html':'UTF-8'}</th>
          <td>{$mfa_php_current|escape:'html':'UTF-8'}
            <span class="mfa-pill {if $mfa_php_ok}mfa-pill-ok{else}mfa-pill-nok{/if}">{if $mfa_php_ok}{$mfa_tr.ok|escape:'html':'UTF-8'}{else}{$mfa_tr.nok|escape:'html':'UTF-8'}{/if}</span>
          </td>
        </tr>
        <tr>
          <th>{$mfa_tr.ps_detected|escape:'html':'UTF-8'}</th>
          <td>{$mfa_ps_current|escape:'html':'UTF-8'}
            <span class="mfa-pill {if $mfa_ps_ok}mfa-pill-ok{else}mfa-pill-nok{/if}">{if $mfa_ps_ok}{$mfa_tr.ok|escape:'html':'UTF-8'}{else}{$mfa_tr.nok|escape:'html':'UTF-8'}{/if}</span>
          </td>
        </tr>
        <tr>
          <th>{$mfa_tr.logo_dir|escape:'html':'UTF-8'}</th>
          <td>{$mfa_logo_dir|escape:'html':'UTF-8'}</td>
        </tr>
        <tr>
          <th>{$mfa_tr.logo_files|escape:'html':'UTF-8'}</th>
          <td>{$mfa_logo_files|intval}</td>
        </tr>
        <tr>
          <th>{$mfa_tr.gd|escape:'html':'UTF-8'}</th>
          <td><span class="mfa-pill {if $mfa_gd}mfa-pill-ok{else}mfa-pill-nok{/if}">{if $mfa_gd}{$mfa_tr.available|escape:'html':'UTF-8'}{else}{$mfa_tr.not_available|escape:'html':'UTF-8'}{/if}</span></td>
        </tr>
        <tr>
          <th>{$mfa_tr.module_version|escape:'html':'UTF-8'}</th>
          <td>{$mfa_version|escape:'html':'UTF-8'}</td>
        </tr>
      </table>
    </div>
  </div>

  {* ------------------------------ EXCEPTIONS MODAL ------------------------------ *}
  <div class="mfa-modal" id="mfa-modal" style="display:none" role="dialog" aria-modal="true">
    <div class="mfa-modal-box">
      <button type="button" class="mfa-modal-close" id="mfa-modal-close" title="{$mfa_tr.btn_close|escape:'html':'UTF-8'}" aria-label="{$mfa_tr.btn_close|escape:'html':'UTF-8'}">&times;</button>
      <h3 id="mfa-modal-title"></h3>
      <div class="mfa-modal-filters">
        <input type="text" id="mfa-f-name" placeholder="{$mfa_tr.f_name|escape:'html':'UTF-8'}" autocomplete="off">
        <input type="text" id="mfa-f-ref" placeholder="{$mfa_tr.f_ref|escape:'html':'UTF-8'}" autocomplete="off">
      </div>
      <div class="mfa-modal-tools">
        <a href="#" id="mfa-sel-all">{$mfa_tr.sel_all|escape:'html':'UTF-8'}</a>
        <a href="#" id="mfa-sel-none">{$mfa_tr.sel_none|escape:'html':'UTF-8'}</a>
        <span id="mfa-sel-count" class="mfa-sel-count"></span>
      </div>
      <div class="mfa-modal-scroll">
        <table class="mfa-ptable">
          <thead>
            <tr>
              <th>{$mfa_tr.th_exc|escape:'html':'UTF-8'}</th>
              <th>{$mfa_tr.th_id|escape:'html':'UTF-8'}</th>
              <th>{$mfa_tr.th_code|escape:'html':'UTF-8'}</th>
              <th>{$mfa_tr.th_name|escape:'html':'UTF-8'}</th>
            </tr>
          </thead>
          <tbody id="mfa-modal-body"></tbody>
        </table>
        <p id="mfa-modal-empty" class="mfa-empty" style="display:none"></p>
        <p class="mfa-more-wrap"><button type="button" class="mfa-btn" id="mfa-more" style="display:none">{$mfa_tr.load_more|escape:'html':'UTF-8'}</button></p>
      </div>
      <div class="mfa-modal-footer">
        <button type="button" class="mfa-btn mfa-btn-primary" id="mfa-exc-save">{$mfa_tr.btn_save_exc|escape:'html':'UTF-8'}</button>
        <button type="button" class="mfa-btn mfa-btn-danger" id="mfa-exc-clear">{$mfa_tr.btn_clear|escape:'html':'UTF-8'}</button>
      </div>
    </div>
  </div>

  {* ------------------------------ LABELS MODAL ------------------------------ *}
  <div class="mfa-modal" id="mfa-label-modal" style="display:none" role="dialog" aria-modal="true">
    <div class="mfa-modal-box mfa-modal-small">
      <button type="button" class="mfa-modal-close" id="mfa-label-close" title="{$mfa_tr.btn_close|escape:'html':'UTF-8'}" aria-label="{$mfa_tr.btn_close|escape:'html':'UTF-8'}">&times;</button>
      <h3 id="mfa-label-title"></h3>
      <div id="mfa-label-body" class="mfa-label-body"></div>
      <div class="mfa-modal-footer" id="mfa-label-footer"></div>
    </div>
  </div>
</div>

<script src="{$mfa_js|escape:'html':'UTF-8'}"></script>
