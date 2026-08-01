<script lang="ts">
  import { t } from '../lib/i18n';
  let copied = $state(false);
  async function copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(window.location.href);
    } catch {
      const input = document.createElement('textarea');
      input.value = window.location.href;
      document.body.append(input);
      input.select();
      document.execCommand('copy');
      input.remove();
    }
    copied = true;
    window.setTimeout(() => (copied = false), 1800);
  }
</script>

<button type="button" onclick={copy}>{copied ? t('copied_link') : t('copy_link')}</button>

<style>
  button {
    min-height: 2.5rem;
    margin: 0;
    padding: 0.35rem 0.65rem;
    border: 1px solid var(--border, #dcd6cb);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcfa);
    color: var(--ink, #33291f);
    font: inherit;
    font-size: var(--text-xs, 0.75rem);
    cursor: pointer;
  }
  button:hover {
    border-color: var(--primary, #007a50);
    color: var(--primary, #007a50);
  }
</style>
