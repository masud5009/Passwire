        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="p-4 rounded-xl bg-[#09090b] border border-white/5 space-y-3">
                <div class="space-y-2.5">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400">Logo (Dark Theme)</label>
                    <div id="previewWrapLogoDark" class="rounded-xl border border-white/10 bg-white/[0.02] p-3 flex items-center justify-center {{ $siteSettings->logoDarkUrl() ? '' : 'hidden' }}">
                        <img id="previewImgLogoDark" src="{{ $siteSettings->logoDarkUrl() ?? '' }}" alt="Dark theme logo" class="max-w-full max-h-24 w-auto h-auto object-contain">
                    </div>
                    <span id="previewEmptyLogoDark" class="text-xs text-zinc-500 font-mono {{ $siteSettings->logoDarkUrl() ? 'hidden' : '' }}">No dark logo uploaded</span>
                </div>
                <input type="file" id="inputLogoDark" name="logo_dark" accept="image/*" class="btn-file w-full text-xs text-zinc-400 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:font-bold file:text-xs file:cursor-pointer bg-[#121215] border border-white/10 rounded-xl p-2">
                @if($siteSettings->logo_dark_path)
                    <label class="flex items-center gap-2 text-xs text-zinc-400 cursor-pointer">
                        <input type="checkbox" name="remove_logo_dark" value="1" class="rounded border-zinc-700 text-rose-500 focus:ring-rose-500">
                        <span>Remove dark logo</span>
                    </label>
                @endif
            </div>
            <div class="p-4 rounded-xl bg-[#09090b] border border-white/5 space-y-3">
                <div class="space-y-2.5">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400">Logo (Light Theme)</label>
                    <div id="previewWrapLogoLight" class="rounded-xl border border-white/10 bg-white/[0.02] p-3 flex items-center justify-center {{ $siteSettings->logoLightUrl() ? '' : 'hidden' }}">
                        <img id="previewImgLogoLight" src="{{ $siteSettings->logoLightUrl() ?? '' }}" alt="Light theme logo" class="max-w-full max-h-24 w-auto h-auto object-contain">
                    </div>
                    <span id="previewEmptyLogoLight" class="text-xs text-zinc-500 font-mono {{ $siteSettings->logoLightUrl() ? 'hidden' : '' }}">No light logo uploaded</span>
                </div>
                <input type="file" id="inputLogoLight" name="logo_light" accept="image/*" class="btn-file w-full text-xs text-zinc-400 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:font-bold file:text-xs file:cursor-pointer bg-[#121215] border border-white/10 rounded-xl p-2">
                @if($siteSettings->logo_light_path)
                    <label class="flex items-center gap-2 text-xs text-zinc-400 cursor-pointer">
                        <input type="checkbox" name="remove_logo_light" value="1" class="rounded border-zinc-700 text-rose-500 focus:ring-rose-500">
                        <span>Remove light logo</span>
                    </label>
                @endif
            </div>
            <div class="p-4 rounded-xl bg-[#09090b] border border-white/5 space-y-3">
                <div class="space-y-2.5">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400">Favicon</label>
                    <div id="previewWrapFavicon" class="rounded-xl border border-white/10 bg-white/[0.02] p-3 flex items-center justify-center {{ $siteSettings->faviconUrl() ? '' : 'hidden' }}">
                        <img id="previewImgFavicon" src="{{ $siteSettings->faviconUrl() ?? '' }}" alt="Current favicon" class="max-w-full max-h-24 w-auto h-auto object-contain">
                    </div>
                    <span id="previewEmptyFavicon" class="text-xs text-zinc-500 font-mono {{ $siteSettings->faviconUrl() ? 'hidden' : '' }}">Default favicon.ico</span>
                </div>
                <input type="file" id="inputFavicon" name="favicon" accept=".ico,.png,.jpg,.jpeg,.svg,.webp" class="btn-file w-full text-xs text-zinc-400 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:font-bold file:text-xs file:cursor-pointer bg-[#121215] border border-white/10 rounded-xl p-2">
                @if($siteSettings->favicon_path)
                    <label class="flex items-center gap-2 text-xs text-zinc-400 cursor-pointer">
                        <input type="checkbox" name="remove_favicon" value="1" class="rounded border-zinc-700 text-rose-500 focus:ring-rose-500">
                        <span>Remove current favicon</span>
                    </label>
                @endif
            </div>
        </div>
        <div class="pt-3 border-t border-white/[0.06] flex items-center justify-end">
            <button type="submit" class="btn-primary px-5 py-2.5 text-white font-bold text-xs rounded-xl transition-all shadow-md flex items-center gap-2 cursor-pointer">
                <i data-lucide="save" class="w-3.5 h-3.5"></i>
                <span>Save Website Settings</span>
            </button>
        </div>
    </form>
</div>

<script>
(function() {
    function bindInstantPreview(inputId, imgId, wrapId, emptyId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const img = document.getElementById(imgId);
        const wrap = document.getElementById(wrapId);
        const empty = document.getElementById(emptyId);
        let objectUrl = null;
        input.addEventListener('change', function() {
            const file = input.files && input.files[0];
            if (!file) return;
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(file);
            img.src = objectUrl;
            wrap.classList.remove('hidden');
            if (empty) empty.classList.add('hidden');
        });
    }
    bindInstantPreview('inputLogoDark', 'previewImgLogoDark', 'previewWrapLogoDark', 'previewEmptyLogoDark');
    bindInstantPreview('inputLogoLight', 'previewImgLogoLight', 'previewWrapLogoLight', 'previewEmptyLogoLight');
    bindInstantPreview('inputFavicon', 'previewImgFavicon', 'previewWrapFavicon', 'previewEmptyFavicon');
})();
</script>
