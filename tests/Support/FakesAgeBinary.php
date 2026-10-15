<?php

namespace SoftArtisan\Vanguard\Tests\Support;

/**
 * A stand-in for the age binary, so the encryption paths can be tested on a
 * machine that has no age installed.
 *
 * It honours the argument shape Vanguard uses — `-e -r R… [-R file] -o out in`
 * and `-d -i identity -o out in` — and "encrypts" by writing the age header
 * followed by the base64 of the input: not a readable tar, and reversible by
 * the same script. Every invocation appends its recipients to `age.log` next
 * to the script, so a test can assert what was handed over.
 */
trait FakesAgeBinary
{
    /**
     * @param  string  $dir  Directory to write the script (and its log) into
     * @param  string|null  $body  Replace the whole behaviour with this shell snippet
     * @return string Absolute path of the executable script
     */
    protected function fakeAgeBinary(string $dir, ?string $body = null): string
    {
        $path = $dir.'/fake-age-'.uniqid();
        $log = $dir.'/age.log';

        $body ??= <<<SH
mode=""; out=""; in=""; recips=""; rfile=""; ident=""
while [ \$# -gt 0 ]; do
  case "\$1" in
    -e) mode=e ;;
    -d) mode=d ;;
    -r) shift; recips="\$recips \$1" ;;
    -R) shift; rfile="\$1" ;;
    -i) shift; ident="\$1" ;;
    -o) shift; out="\$1" ;;
    *) in="\$1" ;;
  esac
  shift
done
echo "mode=\$mode recipients=\$recips file=\$rfile" >> '{$log}'
echo "fake-age: diagnostic on stderr" >&2
if [ "\$mode" = e ]; then
  printf 'age-encryption.org/v1\\n' > "\$out" && base64 < "\$in" >> "\$out"
elif [ "\$mode" = d ]; then
  [ -r "\$ident" ] || { echo "no identity" >&2; exit 1; }
  tail -n +2 "\$in" | base64 -d > "\$out"
else
  exit 2
fi
SH;

        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0755);

        return $path;
    }

    /**
     * A syntactically valid age public key. Public keys are not secrets.
     */
    protected function ageRecipient(string $fill = 'q'): string
    {
        return 'age1'.str_repeat($fill, 58);
    }
}
