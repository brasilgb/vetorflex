import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { home, login, register } from '@/routes';
import { privacy, terms } from '@/routes/legal';

/** Moldura das páginas públicas institucionais (termos, privacidade), com a mesma identidade da página comercial. */
export default function PublicShell({
    company,
    children,
}: {
    company: string;
    children: ReactNode;
}) {
    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b">
                <div className="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-3">
                    <Link
                        href={home()}
                        className="flex items-center gap-2 font-semibold"
                    >
                        <span className="flex size-8 items-center justify-center rounded-md bg-brand text-brand-foreground">
                            <AppLogoIcon className="size-5 fill-current" />
                        </span>
                        VetorFlex
                    </Link>
                    <div className="flex gap-2">
                        <Button variant="ghost" asChild>
                            <Link href={login()}>Entrar</Link>
                        </Button>
                        <Button
                            asChild
                            className="bg-brand text-brand-foreground hover:bg-brand/90"
                        >
                            <Link href={register()}>Teste grátis</Link>
                        </Button>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-3xl px-4 py-10">{children}</main>
            <footer className="border-t">
                <div className="mx-auto flex max-w-4xl flex-wrap justify-between gap-4 px-4 py-6 text-sm text-muted-foreground">
                    <p>VetorFlex é um produto {company}.</p>
                    <nav aria-label="Institucional" className="flex gap-4">
                        <Link href={privacy()}>Política de privacidade</Link>
                        <Link href={terms()}>Termos de uso</Link>
                    </nav>
                </div>
            </footer>
        </div>
    );
}
