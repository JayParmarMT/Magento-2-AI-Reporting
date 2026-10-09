<?php
/**
 * Meetanshi AIReporting — model <select> field with a "Fetch latest models" button.
 *
 * Renders the normal dropdown (preserving the saved value) and a button that
 * calls the fetchModels AJAX endpoint to repopulate the options with the
 * provider's live model list.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ModelField extends Field
{
    /**
     * Provider code is derived from the field id, e.g. "openai_model" -> "openai".
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $provider = $this->resolveProvider($element);
        $selectHtml = parent::_getElementHtml($element);

        $fetchUrl = $this->getUrl('meetanshi_aireporting/config/fetchModels');
        $testUrl  = $this->getUrl('meetanshi_aireporting/config/testConnection');
        $elementId = $element->getHtmlId();

        $buttonLabel = (string) __('Fetch Latest Models');
        $loadingLabel = (string) __('Fetching…');
        $testLabel = (string) __('Test Connection');
        $testingLabel = (string) __('Testing…');

        $html = $selectHtml;
        $html .= <<<HTML
<button type="button" id="{$elementId}_fetch" class="action-default scalable" style="margin-left:8px;">
    <span>{$buttonLabel}</span>
</button>
<button type="button" id="{$elementId}_test" class="action-default scalable" style="margin-left:4px;">
    <span>{$testLabel}</span>
</button>
<span id="{$elementId}_fetch_msg" style="margin-left:8px;font-size:12px;"></span>
<script>
require(['jquery', 'prototype'], function ($) {
    var select  = document.getElementById('{$elementId}');
    var button  = document.getElementById('{$elementId}_fetch');
    var testBtn = document.getElementById('{$elementId}_test');
    var msgEl   = document.getElementById('{$elementId}_fetch_msg');
    if (!select || !button) { return; }

    // Uses the saved API key and model: changes must be saved first
    testBtn.addEventListener('click', function () {
        testBtn.disabled = true;
        var original = testBtn.innerHTML;
        testBtn.innerHTML = '<span>{$testingLabel}</span>';
        msgEl.textContent = '';
        msgEl.style.color = '#666';

        new Ajax.Request('{$testUrl}', {
            method: 'post',
            parameters: { provider: '{$provider}', isAjax: true, form_key: window.FORM_KEY },
            onComplete: function (t) {
                testBtn.disabled = false;
                testBtn.innerHTML = original;
                var res;
                try { res = t.responseText.evalJSON(); } catch (e) { res = null; }
                msgEl.style.color = (res && res.success) ? '#1a7f37' : '#e02b27';
                msgEl.textContent = (res && res.message) ? res.message : 'Request failed. Please try again.';
            }
        });
    });

    button.addEventListener('click', function () {
        var saved = select.value;
        button.disabled = true;
        var original = button.innerHTML;
        button.innerHTML = '<span>{$loadingLabel}</span>';
        msgEl.textContent = '';
        msgEl.style.color = '#666';

        new Ajax.Request('{$fetchUrl}', {
            method: 'post',
            parameters: { provider: '{$provider}', isAjax: true, form_key: window.FORM_KEY },
            onSuccess: function (t) {
                button.disabled = false;
                button.innerHTML = original;
                var res;
                try { res = t.responseText.evalJSON(); } catch (e) { res = null; }
                if (!res || !res.success) {
                    msgEl.style.color = '#e02b27';
                    msgEl.textContent = (res && res.message) ? res.message : 'Failed to fetch models.';
                    return;
                }
                // Rebuild options, keeping the previously-saved value selected if still present
                select.options.length = 0;
                var keepPresent = false;
                res.models.forEach(function (m) {
                    var opt = document.createElement('option');
                    opt.value = m.value;
                    opt.text  = m.label || m.value;
                    if (m.value === saved) { opt.selected = true; keepPresent = true; }
                    select.appendChild(opt);
                });
                if (!keepPresent && saved) {
                    // Preserve the saved value even if the API no longer lists it
                    var opt = document.createElement('option');
                    opt.value = saved;
                    opt.text  = saved + ' (saved)';
                    opt.selected = true;
                    select.insertBefore(opt, select.firstChild);
                }
                msgEl.style.color = '#1a7f37';
                msgEl.textContent = res.models.length + ' models loaded. Remember to Save Config.';
            },
            onFailure: function () {
                button.disabled = false;
                button.innerHTML = original;
                msgEl.style.color = '#e02b27';
                msgEl.textContent = 'Request failed. Please try again.';
            }
        });
    });
});
</script>
HTML;

        return $html;
    }

    /**
     * Derive the provider code from the config field id (e.g. "..._gemini_model" -> "gemini").
     */
    private function resolveProvider(AbstractElement $element): string
    {
        $id = (string) $element->getId();
        foreach (['openai', 'gemini', 'groq', 'openrouter', 'ollama', 'claude'] as $provider) {
            if (str_contains($id, $provider)) {
                return $provider;
            }
        }
        return '';
    }
}
