import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';

/**
 * Moldura das páginas de acesso com a identidade da página comercial: painel da marca em telas grandes e formulário
 * centralizado. `wide` dá espaço à jornada de cadastro sem rolagem excessiva em desktop.
 */
export default function AuthLayout({
    title = '',
    description = '',
    wide = false,
    children,
}: {
    title?: string;
    description?: string;
    wide?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,26rem)_1fr]">
            <aside className="relative hidden flex-col justify-between bg-brand p-10 text-brand-foreground lg:flex">
                <Link
                    href={home()}
                    className="flex items-center gap-2 text-lg font-semibold"
                >
                    <span className="flex size-9 items-center justify-center rounded-md bg-brand-foreground/15">
                        <AppLogoIcon className="size-6 fill-current" />
                    </span>
                    VetorFlex
                </Link>
                <div className="space-y-5">
                    <p className="text-2xl leading-snug font-semibold">
                        Sua empresa, suas aplicações, seu jeito de trabalhar.
                    </p>
                    <ul className="space-y-2 text-sm opacity-90">
                        {[
                            'Contrate só o que precisa',
                            'Amplie quando a empresa crescer',
                            'Dados separados e protegidos',
                        ].map((item) => (
                            <li key={item} className="flex items-center gap-2">
                                <Check className="size-4" aria-hidden="true" />
                                {item}
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="text-xs opacity-75">
                    Um produto ABrasilSistemas.
                </p>
            </aside>
            <main className="flex items-center justify-center p-6 md:p-10">
                <div
                    className={`w-full ${wide ? 'max-w-3xl' : 'max-w-sm'} space-y-8`}
                >
                    <div className="space-y-4">
                        <Link
                            href={home()}
                            className="flex items-center gap-2 font-semibold lg:hidden"
                        >
                            <span className="flex size-8 items-center justify-center rounded-md bg-brand text-brand-foreground">
                                <AppLogoIcon className="size-5 fill-current" />
                            </span>
                            VetorFlex
                        </Link>
                        <div className="space-y-1">
                            <h1 className="text-2xl font-semibold">{title}</h1>
                            {description && (
                                <p className="text-sm text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    </div>
                    {children}
                </div>
            </main>
        </div>
    );
}
