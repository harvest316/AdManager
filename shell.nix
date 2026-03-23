{ pkgs ? import <nixpkgs> {} }:

pkgs.mkShell {
  name = "admanager";

  buildInputs = with pkgs; [
    php83
    php83Packages.composer
  ];
}
