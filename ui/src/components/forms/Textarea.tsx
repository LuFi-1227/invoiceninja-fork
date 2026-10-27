/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2022. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

import classNames from 'classnames';
import { useColorScheme } from '$app/common/colors';
import { useReactSettings } from '$app/common/hooks/useReactSettings';
import CommonProps from '../../common/interfaces/common-props.interface';
import { InputLabel } from './InputLabel';

interface Props extends CommonProps {
  label?: string;
  placeholder?: string;
  rows?: number | undefined;
  border?: boolean;
}

export function Textarea(props: Props) {
  const colors = useColorScheme();
  const reactSettings = useReactSettings();

  return (
    <section style={{ width: props.width }}>
      {props.label && (
        <InputLabel className="mb-2" for={props.id}>
          {props.label}
        </InputLabel>
      )}

      <textarea
        rows={props.rows ?? 5}
        id={props.id}
        style={{
          backgroundColor: colors.$1,
          color: colors.$3,
          ...props.style,
        }}
        className={classNames(
          `form-textarea w-full py-2 px-3 rounded-md text-sm disabled:opacity-75 disabled:cursor-not-allowed focus:outline-none focus:ring-0 ${props.className || ''}`,
          {
            border: props.border !== false,
            'border-[#09090B26] focus:border-black': !reactSettings?.dark_mode,
            'border-[#1f2e41] focus:border-white': reactSettings?.dark_mode,
          }
        )}
        placeholder={props.placeholder}
        onChange={props.onChange}
        value={props.value}
      >
        {props.children}
      </textarea>
    </section>
  );
}
