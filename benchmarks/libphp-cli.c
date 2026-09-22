/* Exercise PHP 8.6's CLI entry point from the shared library used by embedders. */
extern int do_php_cli(int argc, char **argv);

int main(int argc, char **argv)
{
    return do_php_cli(argc, argv);
}
