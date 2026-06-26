{
  pkgs ? import <nixpkgs> { config.allowUnfree = true; },
}:
let
  php = pkgs.php84.buildEnv {
    extensions = { enabled, all }: enabled ++ (with all; [ imagick ]);
    extraConfig = "memory_limit=-1";
  };
in
pkgs.mkShell {
  buildInputs = with pkgs; [
    php
    php.packages.composer

    nodejs_24
    ruby_4_0

    claude-code
    bubblewrap
  ];
}
