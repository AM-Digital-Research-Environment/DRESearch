<script lang="ts">
  import { t } from '../lib/i18n';
  import { announce, COPY_FEEDBACK_MS, surfaceAnnouncer } from '../lib/announce';
  import '../styles/buttons.css';

  // The label reads "Copied" for two seconds and the theme's shared status
  // region (else the surface's status node) says so; `status` is the last
  // resort, for a button rendered outside any search surface.
  const surface = surfaceAnnouncer();
  let copied = $state(false);
  let status = $state('');
  let timer: number | undefined;

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
    if (!announce(t('copied'), surface)) status = t('copied');
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      copied = false;
      status = '';
    }, COPY_FEEDBACK_MS);
  }

  $effect(() => () => window.clearTimeout(timer));
</script>

<!-- Lucide "link", and "check" while it reads Copied: on phones the label is
     hidden, as the view switch's are, and the check is the visible feedback. -->
<button type="button" class="dre-button-secondary" onclick={copy}
  ><svg
    xmlns="http://www.w3.org/2000/svg"
    width="16"
    height="16"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    >{#if copied}<path d="M20 6 9 17l-5-5" />{:else}<path
        d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"
      /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />{/if}</svg
  ><span class="dre-copy__label">{copied ? t('copied') : t('copy_link')}</span></button
>{#if !surface}<span class="dre-copy__status" role="status">{status}</span>{/if}

<style>
  /* The shared secondary button (styles/buttons.css), at the toolbar's size. */
  button {
    gap: var(--space-1, 0.25rem);
    padding-inline: var(--space-3, 0.75rem);
  }
  svg {
    flex: none;
  }
  @media (max-width: 599px) {
    button {
      min-width: var(--size-control-lg, 2.75rem);
      padding-inline: 0;
    }
    .dre-copy__label {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
    }
  }
  .dre-copy__status {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
  }
</style>
