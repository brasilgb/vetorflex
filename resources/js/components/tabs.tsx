import type { KeyboardEvent, ReactNode } from 'react';
import { useId, useState } from 'react';
import { cn } from '@/lib/utils';

export type Tab = { key: string; label: string; content: ReactNode };

export default function Tabs({ label, tabs }: { label: string; tabs: Tab[] }) {
    const id = useId();
    const [selectedKey, setSelectedKey] = useState(tabs[0].key);
    const selected = tabs.find(({ key }) => key === selectedKey) ?? tabs[0];

    function moveFocus(event: KeyboardEvent<HTMLDivElement>) {
        const offset = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

        if (offset === undefined) {
            return;
        }

        event.preventDefault();
        const position = tabs.indexOf(selected) + offset;
        const next = tabs[(position + tabs.length) % tabs.length];
        setSelectedKey(next.key);
        document.getElementById(`${id}-tab-${next.key}`)?.focus();
    }

    return (
        <div className="space-y-4">
            <div
                role="tablist"
                aria-label={label}
                className="flex gap-1 overflow-x-auto border-b"
                onKeyDown={moveFocus}
            >
                {tabs.map(({ key, label: tabLabel }) => (
                    <button
                        key={key}
                        id={`${id}-tab-${key}`}
                        type="button"
                        role="tab"
                        aria-selected={key === selected.key}
                        aria-controls={`${id}-panel`}
                        tabIndex={key === selected.key ? 0 : -1}
                        className={cn(
                            '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            key === selected.key
                                ? 'border-primary font-medium'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                        onClick={() => setSelectedKey(key)}
                    >
                        {tabLabel}
                    </button>
                ))}
            </div>
            <div
                id={`${id}-panel`}
                role="tabpanel"
                aria-labelledby={`${id}-tab-${selected.key}`}
                className="space-y-4"
            >
                {selected.content}
            </div>
        </div>
    );
}
