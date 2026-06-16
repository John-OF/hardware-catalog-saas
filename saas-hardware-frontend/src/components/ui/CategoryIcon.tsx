import {
  Cpu,
  Gamepad2,
  MemoryStick,
  CircuitBoard,
  HardDrive,
  PlugZap,
  PcCase,
  Fan,
  Monitor,
  Mouse,
  Folder,
} from 'lucide-react';

// Mapeo de slugs de categoría a íconos SVG de lucide-react.
// Centralizado aquí para que el catálogo público y el panel usen el mismo set.
const ICONS: Record<string, typeof Cpu> = {
  cpu: Cpu,
  gpu: Gamepad2,
  ram: MemoryStick,
  motherboard: CircuitBoard,
  ssd: HardDrive,
  power: PlugZap,
  case: PcCase,
  cooling: Fan,
  monitor: Monitor,
  peripheral: Mouse,
  folder: Folder,
};

interface CategoryIconProps {
  slug: string | null;
  size?: number;
  className?: string;
}

export default function CategoryIcon({ slug, size = 18, className }: CategoryIconProps) {
  const Icon = (slug && ICONS[slug]) || Folder;
  return <Icon size={size} className={className} />;
}
