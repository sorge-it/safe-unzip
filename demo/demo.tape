# The animated demo in the README: two scripts that differ in one `use` line. \ZipArchive loses
# the executable bit and writes an overlap bomb; SafeUnzip keeps the bit and refuses the bomb.
# Run through `make -C demo gif`; https://github.com/charmbracelet/vhs

Output ../output/demo.gif

Set Shell bash
# About 830 pixels wide in the README column, so the font stays readable when the GIF shrinks.
Set Width 1000
Set Height 520
Set FontSize 20
Set Theme "Catppuccin Mocha"
Set Padding 24
Set Framerate 24
Set TypingSpeed 15ms
Set CursorBlink false
# Each command waits for the prompt, so a slow disk does not type into the output.
Set WaitPattern /^\$\s*$/
Set WaitTimeout 60s

# The first frame shows the title, for a reader whose browser does not play the GIF.
Hide
Type "export PS1='$ ' && clear && echo '# ZipArchive and SafeUnzip: two scripts that differ in one use line'"
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
Type "ls -go overlap.zip"
Enter
Wait
Sleep 1s
Type "php ext-zip.php overlap.zip out-3 && du -sh --apparent-size out-3"
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
