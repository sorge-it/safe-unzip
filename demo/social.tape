# The social preview of the GitHub repository: the name and one refusal, 1280 × 640.
# Run through `make -C demo png`; https://github.com/charmbracelet/vhs

Set Shell bash
Set Width 1280
Set Height 640
Set FontSize 26
Set Theme "Catppuccin Mocha"
Set Padding 36
Set CursorBlink false

Hide
Type "export PS1='$ ' && php social.php && sleep 120"
Enter
# The bomb takes seconds to write; wait for the last line, not for a fixed time.
Wait+Screen@90s /No ext-zip/
Show
Sleep 500ms

Screenshot ../output/social.png
# VHS writes the screenshot in the background; ending the tape at once can drop it.
Sleep 1s
