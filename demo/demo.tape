# The animated demo in the README: the same script with one other `use`. \ZipArchive loses the
# executable bit and writes an overlap bomb; SafeUnzip keeps the bit and refuses the bomb.
# Run through `make -C demo gif`; https://github.com/charmbracelet/vhs

Output ../output/demo.gif

Set Shell bash
Set Width 1280
Set Height 560
Set FontSize 16
Set Theme "Catppuccin Mocha"
Set Padding 24
Set Framerate 24
Set TypingSpeed 15ms
Set CursorBlink false
# Each command waits for the prompt, so a slow disk does not type into the output.
Set WaitPattern /^\$\s*$/
Set WaitTimeout 60s

Hide
Type "export PS1='$ ' && clear"
Enter
Wait
Show

Sleep 400ms
Type "diff ext-zip.php safe-unzip.php"
Enter
Wait
Sleep 1.5s

Type "php ext-zip.php tool.zip out-1 && ls -go out-1/bin"
Enter
Wait
Sleep 1s
Type "php safe-unzip.php tool.zip out-2 && ls -go out-2/bin"
Enter
Wait
Sleep 2s

Type "clear"
Enter
Wait
Type "ls -goh overlap.zip"
Enter
Wait
Sleep 1s
Type "php ext-zip.php overlap.zip out-3 && du -sh out-3"
Enter
Wait
Sleep 1.5s
Type "php safe-unzip.php overlap.zip out-4; ls out-4"
Enter
Wait
Sleep 5s

Hide
Type "rm -rf out-*"
Enter
Wait
