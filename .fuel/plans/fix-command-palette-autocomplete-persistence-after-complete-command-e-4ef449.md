# Epic: Fix command palette autocomplete persistence after complete command (e-4ef449)

## Problem

When typing `/add ` in the consume TUI command palette, the autocomplete suggestion box persists visually even though suggestions are empty. Once a complete command is entered followed by a space (e.g., `add `), the suggestions should disappear.

## Root Cause Analysis

In `app/Commands/ConsumeCommand.php`:

1. **`updateCommandPaletteSuggestions()` (line 3028)**: Routes input to appropriate suggestion handler
   - `close ` prefix → task suggestions
   - `reopen ` prefix → reopenable task suggestions
   - Else → command suggestions via `updateCommandSuggestions()`

2. **`updateCommandSuggestions()` (line 3053)**: Filters commands by prefix match
   - When input is `add `, no command starts with `add ` (including space)
   - Result: `$this->commandPaletteSuggestions = []` (empty)

3. **`captureCommandPalette()` (line 1098)**: Renders the palette overlay
   - Line 1118: `if ($this->commandPaletteSuggestions !== [])` → renders suggestion box
   - Line 1163: `elseif (str_starts_with($this->commandPaletteInput, 'close '))` → shows "No matching tasks"
   - **Else: NO overlay is created for the suggestion box area**

4. **`renderOverlays()` (line 1292)**: Only renders current overlays
   - If no overlay exists for a region, that region is NOT cleared
   - Previous frame's suggestion box content persists on screen

## Solution

Add clearing logic in `captureCommandPalette()` for the case when:
- Suggestions are empty
- Input represents a complete command with space (e.g., `add `, `pause `, `resume `, `reload `)
- NOT the `close ` or `reopen ` cases (which have special handling)

**Implementation**: Add an else branch after line 1186 in `captureCommandPalette()` that creates empty clearing lines when suggestions are empty but we're past a complete command name.

The simplest approach: detect "complete command + space" pattern and add empty overlay lines to clear the suggestion area.

```php
// After line 1186, before "// Build input line with block cursor"
} else {
    // Suggestions empty and not a task-search command - clear any stale suggestion box
    // Check if input looks like a complete command followed by arguments
    $hasCompleteCommand = false;
    foreach (array_keys(self::PALETTE_COMMANDS) as $cmd) {
        if (str_starts_with($this->commandPaletteInput, $cmd . ' ')) {
            $hasCompleteCommand = true;
            break;
        }
    }

    if ($hasCompleteCommand) {
        // Add empty clearing lines to wipe stale suggestion box
        $boxStartRow = $inputRow - 1 - $maxSlots - 2;
        $overlayStartRow = $boxStartRow;
        for ($i = 0; $i < $maxBoxHeight; $i++) {
            $overlayLines[] = ''; // Empty line clears with \033[K
        }
    }
}
```

## Files to Modify

- `app/Commands/ConsumeCommand.php`:
  - `captureCommandPalette()` method (~line 1186): Add clearing logic for empty suggestions case

## Testing

1. Run `fuel consume`
2. Press `/` to open command palette
3. Type `add` - should see 'add' command suggestion
4. Press space - suggestion box should disappear immediately
5. Type task title and press Enter - task should be created
6. Repeat for other commands: `pause `, `resume `, `reload `

## Implementation Notes

### f-96eeaf: Clear suggestion box overlay when command is complete
**Status**: ✅ Complete

Added else branch in `ConsumeCommand.php:1186` that:
1. Detects when input matches a complete command + space pattern (e.g., `add `, `pause `)
2. Loops through `PALETTE_COMMANDS` keys using `str_starts_with()`
3. If match found, creates empty clearing lines (`$maxBoxHeight` worth) in `$overlayLines`
4. Sets `$overlayStartRow` to consistent position to clear stale suggestion box

**Pattern established**: When suggestions are empty but we need to clear previous overlay content, add empty lines (`''`) to `$overlayLines` array. ScreenBuffer will render these as `\033[K` (clear to end of line), wiping stale content.

**Key decision**: Used `str_starts_with($cmd.' ')` (with space) to detect complete command followed by arguments, avoiding false positives while typing the command name itself.

## Interfaces Created

<!-- Tasks add interfaces/contracts they create -->
