import { useEffect, useState } from 'react';
import { Link2, Upload, Image as ImageIcon } from 'lucide-react';

interface ImageSourceFieldProps {
  label: string;
  hint?: string;
  /** URL actual (modo "pegar URL"). */
  url: string;
  onUrlChange: (value: string) => void;
  /** Archivo seleccionado (modo "subir"). */
  file: File | null;
  onFileChange: (file: File | null) => void;
  accept?: string;
}

// Campo de imagen que permite elegir entre pegar una URL o subir un archivo,
// con vista previa en vivo de cualquiera de las dos fuentes.
export default function ImageSourceField({
  label,
  hint,
  url,
  onUrlChange,
  file,
  onFileChange,
  accept = 'image/*',
}: ImageSourceFieldProps) {
  const [mode, setMode] = useState<'url' | 'upload'>(file ? 'upload' : 'url');
  const [preview, setPreview] = useState('');

  // Vista previa: objeto local si hay archivo, si no la URL escrita.
  useEffect(() => {
    if (file) {
      const objectUrl = URL.createObjectURL(file);
      setPreview(objectUrl);
      return () => URL.revokeObjectURL(objectUrl);
    }
    setPreview(url);
  }, [file, url]);

  return (
    <div className="img-source-field">
      <div className="img-source-head">
        <label>{label}</label>
        <div className="img-source-tabs">
          <button
            type="button"
            className={mode === 'url' ? 'active' : ''}
            onClick={() => { setMode('url'); onFileChange(null); }}
          >
            <Link2 size={13} /> URL
          </button>
          <button
            type="button"
            className={mode === 'upload' ? 'active' : ''}
            onClick={() => setMode('upload')}
          >
            <Upload size={13} /> Subir
          </button>
        </div>
      </div>

      <div className="img-source-body">
        {preview ? (
          <img className="img-source-preview" src={preview} alt="" />
        ) : (
          <span className="img-source-preview placeholder"><ImageIcon size={16} /></span>
        )}

        {mode === 'url' ? (
          <input
            className="premium-input"
            type="url"
            value={url}
            placeholder="https://..."
            onChange={(e) => { onUrlChange(e.target.value); onFileChange(null); }}
          />
        ) : (
          <input
            className="premium-input file-input"
            type="file"
            accept={accept}
            onChange={(e) => onFileChange(e.target.files?.[0] ?? null)}
          />
        )}
      </div>

      {hint && <span className="img-source-hint">{hint}</span>}

      <style>{`
        .img-source-field { display: flex; flex-direction: column; gap: 0.5rem; }
        .img-source-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .img-source-head label { font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); }
        .img-source-tabs { display: inline-flex; border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
        .img-source-tabs button { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; background: transparent; border: none; color: var(--text-muted); font-size: 0.72rem; font-weight: 600; cursor: pointer; transition: var(--transition); }
        .img-source-tabs button.active { background: var(--primary); color: #fff; }
        .img-source-body { display: flex; align-items: center; gap: 0.75rem; }
        .img-source-body .premium-input { flex: 1; }
        .img-source-body .file-input { padding: 0.5rem; font-size: 0.8rem; color: var(--text-secondary); }
        .img-source-preview { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; flex-shrink: 0; border-radius: var(--radius-sm); border: 1px solid var(--border); object-fit: contain; background: rgba(255,255,255,0.03); color: var(--text-muted); }
        .img-source-hint { font-size: 0.72rem; color: var(--text-muted); }
      `}</style>
    </div>
  );
}
